<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $apiUrl;
    protected string $accountSid;
    protected string $authToken;
    protected string $fromNumber;

    public function __construct()
    {
        $this->accountSid = config('services.twilio.account_sid');
        $this->authToken  = config('services.twilio.auth_token');
        $this->fromNumber = config('services.twilio.whatsapp_number'); // e.g. "whatsapp:+14155238886"
        $this->apiUrl = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
    }

    /**
     * Send a text message to a recipient phone number.
     * $to should be bare E.164 digits (e.g., "254746604602") — this method
     * adds the "whatsapp:+" prefix Twilio requires.
     */
    public function sendText(string $to, string $text): array
    {
        $toFormatted = 'whatsapp:+' . ltrim($to, '+');

        $response = Http::asForm()
            ->withBasicAuth($this->accountSid, $this->authToken)
            ->post($this->apiUrl, [
                'From' => $this->fromNumber,
                'To'   => $toFormatted,
                'Body' => $text,
            ]);

        if ($response->failed()) {
            Log::error('WhatsApp send failed', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return ['success' => false, 'error' => $response->body()];
        }

        Log::info('WhatsApp message sent', [
            'to' => $to,
            'message_sid' => $response->json('sid'),
        ]);

        return ['success' => true, 'response' => $response->json()];
    }

    /**
     * Static helper for sending messages without instantiation.
     * Used by ProcessIncomingWhatsAppMessage job.
     */
    public static function sendMessage(string $phoneNumber, string $message): bool
    {
        $service = new self();
        $result = $service->sendText($phoneNumber, $message);
        return $result['success'] ?? false;
    }
}