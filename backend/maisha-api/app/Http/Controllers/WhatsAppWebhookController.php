<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WhatsappMessage;
use App\Models\WhatsappConversationState;
use App\Models\User;
use App\Models\MedicationExtractionReview;
use App\Models\VitalsReading;
use App\Models\ProcessedMedia;
use App\Services\BpCaptureFlow;
use App\Services\SugarCaptureFlow;
use App\Services\WhatsAppService;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Jobs\ProcessIncomingPhoto;

class WhatsAppWebhookController extends Controller
{
    /**
     * Optional GET verification (kept for compatibility – Twilio doesn't use it).
     */
    public function verify(Request $request)
    {
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Twilio POSTs incoming messages here as form‑encoded data.
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('WhatsApp webhook received', $payload);

        // 1. Extract Twilio fields
        $text        = trim($payload['Body'] ?? '');
        $senderPhone = $payload['From'] ?? null; // e.g. "whatsapp:+254746604602"

        if (!$senderPhone) {
            return response('OK', 200);
        }

        // 2. Resolve local user from phone number
        $user = $this->resolveUserByPhone($senderPhone);

        if (!$user) {
            Log::warning('WhatsApp message from unknown user', ['phone' => $senderPhone]);
            $this->storeRawMessage($payload);
            return response('OK', 200);
        }

        $userId = $user->id;

        // 2b. Media takes priority over any text-based routing below — a photo
        //     short-circuits the rest of the handler entirely. All slow work
        //     (download, downscale, classify, route, extract, save, reply)
        //     now happens in a queued job, not inline — this webhook only
        //     claims the media_sid and dispatches, so it returns to Twilio
        //     near-instantly regardless of how long classification takes.
        $numMedia = (int) ($payload['NumMedia'] ?? 0);

        if ($numMedia > 0) {
            $this->handleIncomingPhoto($payload, $user, $senderPhone);
            $this->storeRawMessage($payload);
            return response('OK', 200);
        }

        // 3. Check for active conversation state, clean up if expired
        $state = WhatsappConversationState::where('user_id', $userId)->first();

        if ($state && $state->expires_at->isPast()) {
            $state->delete();
            $state = null;
        }

        // 4. Check for a flow-trigger phrase FIRST
        if (preg_match('/\b(bp|blood pressure)\b/i', $text)) {
            if ($state) {
                $state->delete();
            }
            $result = (new BpCaptureFlow())->start($userId);
            $this->sendWhatsAppReply($senderPhone, $result['reply']);
            $this->storeRawMessage($payload);
            return response('OK', 200);
        }

        if (preg_match('/\b(sugar|glucose)\b/i', $text)) {
            if ($state) {
                $state->delete();
            }
            $result = (new SugarCaptureFlow())->start($userId);
            $this->sendWhatsAppReply($senderPhone, $result['reply']);
            $this->storeRawMessage($payload);
            return response('OK', 200);
        }

        // 5. No trigger phrase matched — if there's an active state, continue it
        if ($state) {
            $result = match ($state->flow) {
                'bp_capture'    => (new BpCaptureFlow())->handle($state, $text),
                'sugar_capture' => (new SugarCaptureFlow())->handle($state, $text),
                default         => null,
            };

            if ($result) {
                $this->sendWhatsAppReply($senderPhone, $result['reply']);
                $this->storeRawMessage($payload);
                return response('OK', 200);
            }
        }

        // 6. Fallback: no guided flow handled this message — store it and dispatch
        //    the async job so it can classify intent and link this WhatsApp
        //    session to the user. Deliberately NOT moved earlier: this job
        //    calls Flask's /api/intent (paid AI path) unconditionally, so it
        //    must only fire when no guided flow already handled the message.
        $message = $this->storeRawMessage($payload);
        ProcessIncomingWhatsAppMessage::dispatch($message);

        return response('OK', 200);
    }

    /**
     * Claim + dispatch only. All download/classify/route/extract/save/reply
     * logic now lives in ProcessIncomingPhoto (queued job) — this method's
     * only job is to guarantee a given media_sid is claimed exactly once,
     * synchronously, before any slow work starts. This closes the race
     * window where a Twilio-retried webhook (e.g. after a timeout waiting
     * on a slow classify call) could re-trigger a full second classification
     * for the same photo, since the old dedupe check only ran AFTER
     * classification completed and something was saved — a photo that
     * produced a "trouble reaching the image service" reply never reached
     * that save step, so a retry could run the whole pipeline again.
     *
     * NOT YET CONFIRMED SAFE TO DEPLOY: this assumes a queue worker is
     * actually running (QUEUE_CONNECTION=database requires one). If it's
     * not running, dispatched jobs will sit in the jobs table indefinitely
     * and photos will silently produce no reply at all — confirm via
     * `ps aux | grep "queue:work"` before shipping this.
     */
    private function handleIncomingPhoto(array $payload, User $user, string $senderPhone): void
    {
        $mediaUrl = $payload['MediaUrl0'] ?? null;
        $mediaSid = $payload['MediaSid0'] ?? ($payload['SmsMessageSid'] ?? uniqid());

        if (!$mediaUrl) {
            Log::warning('Photo message had no MediaUrl0', ['payload' => $payload]);
            return;
        }

        // Dedupe BEFORE dispatch, not just before save — this is the actual
        // fix for the duplicate-reply issue, pending confirmation via
        // grep -c "trouble reaching the image service" storage/logs/laravel.log
        // that the duplicate was real and not a WhatsApp client-side artifact.
        if (MedicationExtractionReview::where('media_sid', $mediaSid)->exists()
            || VitalsReading::where('media_sid', $mediaSid)->exists()
            || ProcessedMedia::where('media_sid', $mediaSid)->exists()) {
            Log::info('Media already processed or in-flight, skipping', ['media_sid' => $mediaSid]);
            return;
        }

        // Claim this media_sid immediately, synchronously, so a Twilio retry
        // arriving seconds later (before the job even finishes) still gets
        // caught by the check above.
        ProcessedMedia::create(['media_sid' => $mediaSid]);

        ProcessIncomingPhoto::dispatch($mediaUrl, $mediaSid, $user->id, $senderPhone);
    }

    /**
     * Store the raw webhook payload in the database.
     */
    private function storeRawMessage(array $payload): WhatsappMessage
    {
        return WhatsappMessage::create([
            'payload'     => $payload,
            'received_at' => now(),
        ]);
    }

    /**
     * Resolve a local user by WhatsApp phone number.
     * Twilio's "From" includes "whatsapp:" prefix; strip all non‑digits.
     */
    private function resolveUserByPhone(string $phone): ?User
    {
        $normalized = preg_replace('/[^0-9]/', '', $phone);
        return User::where('phone', $normalized)->first();
    }

    /**
     * Send a reply using your existing WhatsAppService.
     * WhatsAppService::sendText() expects bare digits (no prefix).
     */
    private function sendWhatsAppReply(string $toPhone, string $replyText): void
    {
        $bareDigits = preg_replace('/[^0-9]/', '', $toPhone);
        app(WhatsAppService::class)->sendText($bareDigits, $replyText);
    }
}