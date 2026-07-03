<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\WhatsappMessage;
use App\Jobs\ProcessIncomingWhatsAppMessage;

class WhatsAppWebhookController extends Controller
{
    /**
     * Meta sends a GET request to verify the webhook.
     */
    public function verify(Request $request)
    {
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        // The verify token must match the one in your .env
        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Meta POSTs incoming messages here.
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        // Log the raw payload for audit
        Log::info('WhatsApp webhook received', $payload);

        // Store in database for later processing
        $message = WhatsappMessage::create([
            'payload' => $payload,
            'received_at' => now(),
        ]);

        // Dispatch async job to process the message
        ProcessIncomingWhatsAppMessage::dispatch($message);

        // Meta expects a 200 OK with no body (return immediately, don't wait for job)
        return response('OK', 200);
    }
}
