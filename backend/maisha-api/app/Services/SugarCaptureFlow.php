<?php

namespace App\Services;

use App\Models\VitalsReading;
use App\Models\WhatsappConversationState;

class SugarCaptureFlow
{
    private const PLAUSIBLE_RANGE_MG_DL = [30, 500];

    public function start(int $userId): array
    {
        WhatsappConversationState::updateOrCreate(
            ['user_id' => $userId],
            ['flow' => 'sugar_capture', 'step' => 'awaiting_sugar', 'context' => [], 'expires_at' => now()->addMinutes(30)]
        );
        return ['reply' => "What's your sugar reading? (the number from your glucometer, mg/dL)"];
    }

    public function handle(WhatsappConversationState $state, string $body): array
    {
        $body = trim($body);

        if (strtoupper($body) === 'SKIP') {
            $state->delete();
            return ['reply' => "No problem — we'll ask again tomorrow.", 'done' => true];
        }

        if ($state->step === 'confirming_outlier' && $this->isConfirmation($body)) {
            return $this->finish($state, $state->context['pending_value']);
        }
        if ($state->step === 'confirming_outlier') {
            $state->update(['step' => 'awaiting_sugar', 'expires_at' => now()->addMinutes(30)]);
            return ['reply' => "No problem — please resend your sugar reading.", 'done' => false];
        }

        if (!preg_match('/^\s*(\d{1,3}(\.\d)?)\s*$/', $body, $m)) {
            return ['reply' => "That doesn't look like a number. Please send just digits, like 140", 'done' => false];
        }
        $value = (float) $m[1];

        if ($value < self::PLAUSIBLE_RANGE_MG_DL[0] || $value > self::PLAUSIBLE_RANGE_MG_DL[1]) {
            $state->update([
                'step' => 'confirming_outlier',
                'context' => ['pending_value' => $value],
                'expires_at' => now()->addMinutes(30),
            ]);
            return ['reply' => "That number seems unusual — reply YES to confirm {$value}, or resend a different number.", 'done' => false];
        }

        return $this->finish($state, $value);
    }

    private function finish(WhatsappConversationState $state, float $value): array
    {
        $isOutlier = $value < self::PLAUSIBLE_RANGE_MG_DL[0] || $value > self::PLAUSIBLE_RANGE_MG_DL[1];

        VitalsReading::create([
            'user_id'      => $state->user_id,
            'type'         => 'sugar',
            'sugar_value'  => $value,
            'sugar_unit'   => 'mg_dl',
            'is_outlier'   => $isOutlier,
            'recorded_via' => 'whatsapp',
            'recorded_at'  => now(),
        ]);
        $state->delete();
        return ['reply' => "✅ Sugar recorded: {$value} mg/dL", 'done' => true];
    }

    private function isConfirmation(string $body): bool
    {
        return in_array(strtoupper(trim($body)), ['YES', 'Y', 'CONFIRM']);
    }
}