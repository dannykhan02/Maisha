<?php

namespace App\Services;

use App\Models\VitalsReading;
use App\Models\WhatsappConversationState;

class TemperatureCaptureFlow
{
    // Mirrors VitalsPlausibilityChecker's bounds. Duplicated here (rather than
    // calling the checker for range membership directly) because this flow
    // needs the two ranges separately to INFER which unit was meant, not just
    // to flag an outlier after the unit is already known.
    private const CELSIUS_RANGE    = [30.0, 43.0];
    private const FAHRENHEIT_RANGE = [86.0, 109.4];

    public function start(int $userId): array
    {
        WhatsappConversationState::updateOrCreate(
            ['user_id' => $userId],
            ['flow' => 'temperature_capture', 'step' => 'awaiting_temperature', 'context' => [], 'expires_at' => now()->addMinutes(30)]
        );
        return ['reply' => "What's your temperature? (e.g. 37.2)"];
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
            $state->update(['step' => 'awaiting_temperature', 'expires_at' => now()->addMinutes(30)]);
            return ['reply' => "No problem — please resend your temperature.", 'done' => false];
        }

        if (!preg_match('/^\s*(\d{2,3}(\.\d)?)\s*(°?[cf])?\s*$/i', $body, $m)) {
            return ['reply' => "That doesn't look like a number. Please send just digits, like 37.2", 'done' => false];
        }

        $value = (float) $m[1];
        $explicitUnit = isset($m[3]) ? strtolower(str_replace('°', '', $m[3])) : null;

        if ($explicitUnit === 'c') {
            $unit = 'celsius';
        } elseif ($explicitUnit === 'f') {
            $unit = 'fahrenheit';
        } else {
            $unit = $this->inferUnit($value);

            if ($unit === null) {
                return [
                    'reply' => "I couldn't tell if that's °C or °F — please resend with the unit, e.g. 37.5C or 99.1F",
                    'done'  => false,
                ];
            }
        }

        if (!$this->isPlausible($value, $unit)) {
            $state->update([
                'step'       => 'confirming_outlier',
                'context'    => ['pending_value' => $value, 'pending_unit' => $unit],
                'expires_at' => now()->addMinutes(30),
            ]);
            $unitSymbol = $unit === 'celsius' ? '°C' : '°F';
            return ['reply' => "That number seems unusual — reply YES to confirm {$value}{$unitSymbol}, or resend a different number.", 'done' => false];
        }

        return $this->finish($state, $value, $unit);
    }

    /**
     * Returns 'celsius' or 'fahrenheit' if the value unambiguously falls in
     * exactly one of the two ranges, or null if it falls in neither (and
     * therefore the unit can't be safely guessed). The two ranges never
     * overlap numerically, so there's no "falls in both" case to handle.
     */
    private function inferUnit(float $value): ?string
    {
        $inCelsius    = $value >= self::CELSIUS_RANGE[0] && $value <= self::CELSIUS_RANGE[1];
        $inFahrenheit = $value >= self::FAHRENHEIT_RANGE[0] && $value <= self::FAHRENHEIT_RANGE[1];

        if ($inCelsius) {
            return 'celsius';
        }
        if ($inFahrenheit) {
            return 'fahrenheit';
        }
        return null;
    }

    private function isPlausible(float $value, string $unit): bool
    {
        return $unit === 'celsius'
            ? ($value >= self::CELSIUS_RANGE[0] && $value <= self::CELSIUS_RANGE[1])
            : ($value >= self::FAHRENHEIT_RANGE[0] && $value <= self::FAHRENHEIT_RANGE[1]);
    }

    private function finish(WhatsappConversationState $state, float $value, string $unit): array
    {
        $isOutlier = !$this->isPlausible($value, $unit);

        VitalsReading::create([
            'user_id'           => $state->user_id,
            'type'              => 'temperature',
            'temperature_value' => $value,
            'temperature_unit'  => $unit,
            'is_outlier'        => $isOutlier,
            'recorded_via'      => 'whatsapp',
            'recorded_at'       => now(),
        ]);
        $state->delete();

        $unitSymbol = $unit === 'celsius' ? '°C' : '°F';
        return ['reply' => "✅ Temperature recorded: {$value}{$unitSymbol}", 'done' => true];
    }

    private function isConfirmation(string $body): bool
    {
        return in_array(strtoupper(trim($body)), ['YES', 'Y', 'CONFIRM']);
    }
}