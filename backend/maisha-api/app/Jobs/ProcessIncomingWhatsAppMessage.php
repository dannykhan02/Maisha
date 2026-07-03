<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSession;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Services\UtakulaaService;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected WhatsappMessage $message;

    public function __construct(WhatsappMessage $message)
    {
        $this->message = $message;
    }

    /**
     * Execute the job.
     * 
     * Flow:
     * 1. Detect payload source (Twilio vs Meta) and extract sender phone, message text
     * 2. Find or create WhatsApp session, link user by wa_number if not already linked
     * 3. If user not linked, send bilingual "not recognized" message and return
     * 4. Call Flask /api/intent to classify message intent
     * 5. Route to appropriate handler (utakulaa, budget, history, help)
     * 6. Send response back via Twilio API
     * 7. Mark WhatsappMessage with processed_at and status
     */
    public function handle(): void
    {
        try {
            // Step 1: Detect payload source and extract sender phone & message text
            $senderPhone = $this->extractSenderPhone($this->message->payload);
            $messageText = $this->extractMessageText($this->message->payload);

            if (!$senderPhone || !$messageText) {
                Log::warning('WhatsApp: Could not extract phone or text', [
                    'payload' => $this->message->payload,
                ]);
                $this->message->update(['processed_at' => now(), 'status' => 'failed']);
                return;
            }

            // Step 2: Find or create WhatsApp session and link user by wa_number
            $session = $this->getOrCreateSession($senderPhone);
            
            // If user not yet linked, try to link by wa_number
            if (!$session->user_id) {
                $user = $this->linkUserByWaNumber($senderPhone);
                if (!$user) {
                    // User not recognized — send bilingual message
                    $this->sendBilingualNotRecognizedMessage($senderPhone);
                    $this->message->update(['processed_at' => now(), 'status' => 'processed']);
                    return;
                }
                // Link the session to the user
                $session->update([
                    'user_id' => $user->id,
                    'linked_at' => now(),
                ]);
            } else {
                $user = $session->user;
            }

            // Step 3: Call Flask to classify intent
            $intent = $this->classifyIntent($messageText, $user);

            // Step 4: Route to handler
            $response = match ($intent) {
                'utakulaa'  => $this->handleUtakulaa($user),
                'budget'    => $this->handleBudget($user, $messageText),
                'history'   => $this->handleHistory($user),
                'help'      => $this->handleHelp(),
                default     => $this->handleUtakulaa($user),
            };

            // Step 5 & 6: Send response via Twilio API
            $service = new WhatsAppService();
            $service->sendText($senderPhone, $response);

            // Step 7: Mark message as processed
            $this->message->update(['processed_at' => now(), 'status' => 'processed']);

            Log::info('WhatsApp message processed', [
                'user_id' => $user->id,
                'intent' => $intent,
                'response_length' => strlen($response),
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Job failed', [
                'error' => $e->getMessage(),
                'message_id' => $this->message->id,
            ]);
            $this->message->update(['processed_at' => now(), 'status' => 'failed']);
            // Don't rethrow — treat as processed with error status
        }
    }

    /**
     * Detect payload source and extract sender phone.
     * Twilio format (flat): { "From": "whatsapp:+254746604602", "WaId": "254746604602", ... }
     * Meta format (nested): { "entry": [{ "changes": [{ "value": { "messages": [{ "from": "1234567890" }] } }] }] }
     * 
     * Returns: bare E.164 digits (e.g., "254746604602")
     */
    private function extractSenderPhone(array $payload): ?string
    {
        // Try Twilio format first (WaId is bare E.164)
        if (isset($payload['WaId'])) {
            return $payload['WaId'];
        }

        // Try Meta format
        $metaPhone = $payload['entry'][0]['changes'][0]['value']['messages'][0]['from'] ?? null;
        if ($metaPhone) {
            return $metaPhone;
        }

        return null;
    }

    /**
     * Extract message text from payload.
     * Twilio format: { "Body": "Hey", ... }
     * Meta format: { "entry": [{ "changes": [{ "value": { "messages": [{ "text": { "body": "..." } }] } }] }] }
     */
    private function extractMessageText(array $payload): ?string
    {
        // Try Twilio format first (flat Body field)
        if (isset($payload['Body'])) {
            return $payload['Body'];
        }

        // Try Meta format (nested)
        return $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] ?? null;
    }

    /**
     * Find or create WhatsApp session by phone number.
     * Uses wa_number as the unique key (bare E.164 digits).
     */
    private function getOrCreateSession(string $phone): WhatsappSession
    {
        return WhatsappSession::firstOrCreate(
            ['wa_number' => $phone],
            ['user_id' => null, 'linked_at' => null]
        );
    }

    /**
     * Look up User by wa_number, defensively handling + prefix.
     * Returns User if found, null otherwise.
     */
    private function linkUserByWaNumber(string $phone): ?User
    {
        // Normalize: remove + if present
        $normalized = ltrim($phone, '+');

        // Try exact match first
        $user = User::where('wa_number', $normalized)->first();
        if ($user) {
            return $user;
        }

        // Try with + prefix
        $user = User::where('wa_number', '+' . $normalized)->first();
        if ($user) {
            return $user;
        }

        return null;
    }

    /**
     * Send bilingual "not recognized" message.
     * Warm, welcoming tone matching Flask system prompt style.
     */
    private function sendBilingualNotRecognizedMessage(string $phone): void
    {
        $message = "Hi there! 👋 I don't recognize this number yet. "
            . "Please sign up at http://localhost:3000/signup to get started with Maisha.\n\n"
            . "---\n\n"
            . "Habari! 👋 Sijui namba hii. "
            . "Tafadhali jisajili kwenye http://localhost:3000/signup kuanza na Maisha.";

        $service = new WhatsAppService();
        $service->sendText($phone, $message);
    }

    /**
     * Call Flask /api/intent to classify message intent.
     * Returns: 'utakulaa' | 'budget' | 'history' | 'help'
     */
    private function classifyIntent(string $message, User $user): string
    {
        try {
            $response = Http::withHeaders([
                'X-Maisha-Internal-Token' => config('services.flask.secret'),
            ])->post(config('services.flask.url') . '/api/intent', [
                'message' => $message,
                'user_context' => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                ],
            ]);

            return $response->json('intent', 'utakulaa');
        } catch (\Exception $e) {
            Log::warning('WhatsApp: Intent classification failed, defaulting to utakulaa', [
                'error' => $e->getMessage(),
            ]);
            return 'utakulaa';
        }
    }

    /**
     * Handle 'utakulaa' intent — get meal suggestion.
     * Calls UtakulaaService::getMealPlan() to build correct Flask payload.
     * Returns explanation text, or friendly fallback on error.
     */
    private function handleUtakulaa(User $user): string
    {
        try {
            $service = new UtakulaaService();
            $budget = $user->daily_budget_kes ?? 500;
            $result = $service->getMealPlan($user, $budget);

            return $result['explanation'] ?? 'I found a great meal suggestion for you! Check the app for details.';
        } catch (\Exception $e) {
            Log::error('WhatsApp: Utakulaa failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);
            return "I'm having trouble generating a meal suggestion right now. Please try again in a moment!";
        }
    }

    /**
     * Handle 'budget' intent — show budget info.
     */
    private function handleBudget(User $user, string $message): string
    {
        // TODO: Parse message for expense logging (e.g., "spent 500 on rice")
        // For now, just return budget summary
        return "Your daily budget: KES {$user->daily_budget_kes}. Spent: KES 0. Remaining: KES {$user->daily_budget_kes}.";
    }

    /**
     * Handle 'history' intent — show recent suggestions.
     */
    private function handleHistory(User $user): string
    {
        $recent = $user->mealSuggestions()->latest()->first();
        if (!$recent) {
            return "No meal suggestions yet. Ask me for a meal suggestion!";
        }
        return "Last suggestion: {$recent->meal_name} (KES {$recent->total_cost_kes})";
    }

    /**
     * Handle 'help' intent — show available commands.
     */
    private function handleHelp(): string
    {
        return "I can help with:\n• Meal suggestions\n• Budget tracking\n• Health info\n• Suggestion history";
    }
}
