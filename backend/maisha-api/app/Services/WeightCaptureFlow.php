<?php

namespace App\Services;

use App\Models\VitalsReading;
use App\Models\WhatsappConversationState;

class WeightCaptureFlow
{
    public function start(int $userId): array
    {
        WhatsappConversationState::updateOrCreate(
            ['user_id' => $userId],
            ['flow' => 'weight_capture', 'step' => 'awaiting_weight', 'context' => [], 'expires_at' => now()->addMinutes(30)]
        );
        return ['reply' => "What's your weight? (e.g. 68 for kg, or 150lbs)"];
    }

    public function handle(WhatsappConversationState $state, string $body): array
    {
        $body = trim($body);

        if (strtoupper($body) === 'SKIP') {
            $state->delete();
            return ['reply' => "No problem — we'll ask again tomorrow.", 'done' => true];
        }

        if ($state->step === 'confirming_outlier' && $this->isConfirmation($body)) {
            return $this->finish($state, $state->context['pending_value'], $state->context['pending_unit']);
        }
        if ($state->step === 'confirming_outlier') {
            $state->update(['step' => 'awaiting_weight', 'expires_at' => now()->addMinutes(30)]);
            return ['reply' => "No problem — please resend your weight.", 'done' => false];
        }

        if (!preg_match('/^\s*(\d{1,3}(\.\d)?)\s*(kgs?|lbs?)?\s*$/i', $body, $m)) {
            return ['reply' => "That doesn't look like a number. Please send just digits, like 68", 'done' => false];
        }

        $value = (float) $m[1];
        $unitToken = isset($m[3]) ? strtolower($m[3]) : null;
        // No unit typed defaults to kg — matches the standard most Maisha
        // users measure in locally. Anyone weighing in pounds needs to
        // type "lbs"/"lb" explicitly; there's no magnitude-based inference
        // like temperature gets, because kg and lbs ranges overlap heavily
        // (e.g. 60 is a plausible adult weight in either unit).
        $unit = str_starts_with($unitToken ?? '', 'lb') ? 'lbs' : 'kg';

        $lastWeight = VitalsReading::where('user_id', $state->user_id)
            ->where('type', 'weight')
            ->whereNotNull('weight_value')
            ->latest('recorded_at')
            ->first();

        $isPlausible = VitalsPlausibilityChecker::isPlausibleWeight(
            $value,
            $unit,
            $lastWeight?->weight_value,
            $lastWeight?->weight_unit
        );

        if (!$isPlausible) {
            $state->update([
                'step'       => 'confirming_outlier',
                'context'    => ['pending_value' => $value, 'pending_unit' => $unit],
                'expires_at' => now()->addMinutes(30),
            ]);
            $note = $lastWeight
                ? "that's a bigger jump from your last reading than usual"
                : "that number looks unusually high or low";
            return ['reply' => "{$value}{$unit} — {$note}. Reply YES to confirm, or resend a different number.", 'done' => false];
        }

        return $this->finish($state, $value, $unit);
    }

    private function finish(WhatsappConversationState $state, float $value, string $unit): array
    {
        $lastWeight = VitalsReading::where('user_id', $state->user_id)
            ->where('type', 'weight')
            ->whereNotNull('weight_value')
            ->latest('recorded_at')
            ->first();

        $isOutlier = !VitalsPlausibilityChecker::isPlausibleWeight(
            $value,
            $unit,
            $lastWeight?->weight_value,
            $lastWeight?->weight_unit
        );

        VitalsReading::create([
            'user_id'      => $state->user_id,
            'type'         => 'weight',
            'weight_value' => $value,
            'weight_unit'  => $unit,
            'is_outlier'   => $isOutlier,
            'recorded_via' => 'whatsapp',
            'recorded_at'  => now(),
        ]);
        $state->delete();

        return ['reply' => "✅ Weight recorded: {$value} {$unit}", 'done' => true];
    }

    private function isConfirmation(string $body): bool
    {
        return in_array(strtoupper(trim($body)), ['YES', 'Y', 'CONFIRM']);
    }
}