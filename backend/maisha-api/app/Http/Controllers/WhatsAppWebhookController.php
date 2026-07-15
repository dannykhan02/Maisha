<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WhatsappMessage;
use App\Models\WhatsappConversationState;
use App\Models\User;
use App\Models\MedicationExtractionReview;
use App\Services\BpCaptureFlow;
use App\Services\SugarCaptureFlow;
use App\Services\WhatsAppService;
use App\Services\MedicationVisionService;
use App\Jobs\ProcessIncomingWhatsAppMessage;

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
            // Not a valid message from a phone number
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

        // 2b. Media (e.g. medication label photo) takes priority over any
        //     text-based routing below — a photo short-circuits the rest of
        //     the handler entirely.
        $numMedia = (int) ($payload['NumMedia'] ?? 0);

        if ($numMedia > 0) {
            $this->handleMedicationPhoto($payload, $user);
            $this->storeRawMessage($payload);
            return response('OK', 200);
        }

        // 3. Check for active conversation state, clean up if expired
        $state = WhatsappConversationState::where('user_id', $userId)->first();

        if ($state && $state->expires_at->isPast()) {
            $state->delete();
            $state = null;
        }

        // 4. Check for a flow-trigger phrase FIRST — this can override/restart a stale state.
        //    A trigger word always wins and resets; only a non-trigger message falls through
        //    to state-continuation below.
        if (preg_match('/\b(bp|blood pressure)\b/i', $text)) {
            if ($state) {
                $state->delete(); // abandon any stale/incomplete flow
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
        //    the async job so it can classify intent (utakulaa/budget/history/help)
        //    and, along the way, link this WhatsApp session to the user.
        //    Deliberately NOT moved earlier in this method: this job calls Flask's
        //    /api/intent (paid AI path) unconditionally, so it must only fire when
        //    no guided flow already handled the message — otherwise a "Bp" trigger
        //    would also hit the AI path in parallel and send a second, unrelated
        //    reply alongside the BP flow's reply.
        $message = $this->storeRawMessage($payload);
        ProcessIncomingWhatsAppMessage::dispatch($message);

        return response('OK', 200);
    }

    /**
     * Handle an incoming WhatsApp media message (e.g. a photo of a medication
     * label/box). Downloads the media from Twilio, downscales it, runs vision
     * extraction, and saves the result for manual review.
     */
    private function handleMedicationPhoto(array $payload, User $user): void
    {
        $mediaUrl = $payload['MediaUrl0'] ?? null;
        $mediaSid = $payload['MediaSid0'] ?? ($payload['SmsMessageSid'] ?? uniqid());

        if (!$mediaUrl) {
            Log::warning('Medication photo message had no MediaUrl0', ['payload' => $payload]);
            return;
        }

        // Dedupe — don't re-extract the same media twice
        if (MedicationExtractionReview::where('media_sid', $mediaSid)->exists()) {
            Log::info('Medication photo already processed, skipping re-extraction', ['media_sid' => $mediaSid]);
            return;
        }

        $vision = new MedicationVisionService();

        $rawBytes = $vision->downloadTwilioMedia($mediaUrl);
        if (!$rawBytes) {
            return; // already logged inside the service
        }

        $jpegBytes = $vision->downscale($rawBytes);
        if (!$jpegBytes) {
            return;
        }

        $extracted = $vision->extract($jpegBytes);
        if (!$extracted) {
            return;
        }

        MedicationExtractionReview::create([
            'user_id'        => $user->id,
            'media_sid'      => $mediaSid,
            'extracted_data' => $extracted,
            'confidence'     => $extracted['confidence'] ?? null,
            'status'         => 'pending',
        ]);

        Log::info('Medication extraction saved for review', [
            'user_id' => $user->id,
            'media_sid' => $mediaSid,
            'readable' => $extracted['readable'] ?? null,
            'confidence' => $extracted['confidence'] ?? null,
        ]);
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
        // "whatsapp:+254746604602" → "254746604602"
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