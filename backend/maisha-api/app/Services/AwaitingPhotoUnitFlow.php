<?php

namespace App\Services;

use App\Models\VitalsReading;
use App\Models\WhatsappConversationState;

/**
 * Captures the follow-up unit reply for a photo-extracted reading whose
 * unit wasn't visible on the device display (sugar/temperature/weight —
 * BP never needs this, it has no unit ambiguity).
 *
 * Context holds a queue: 'pending' => array of
 *   ['type' => 'sugar'|'temperature'|'weight', 'value' => float, 'media_sid' => string]
 * One item is asked about at a time; each answered reply pops the front
 * item and saves it, then either asks about the next queued item or ends
 * the flow. This exists specifically because a single photo can produce
 * MORE THAN ONE unit-less reading (e.g. glucometer + scale, both missing
 * an on-screen unit) — a flat one-shot flow can't represent that.
 */
class AwaitingPhotoUnitFlow
{
    /**
     * Called from ProcessIncomingPhoto (a queued job, not the webhook
     * request) whenever a reading is missing its unit. If a state already
     * exists for this user (e.g. two unit-less readings from the same
     * photo), appends to its pending queue rather than overwriting it —
     * this is the one flow that must NOT blindly updateOrCreate, since
     * doing so would drop an already-queued pending item.
     */
    public static function enqueue(int $userId, string $type, float $value, string $mediaSid): string
    {
        $state = WhatsappConversationState::where('user_id', $userId)->first();

        if ($state && $state->flow === 'awaiting_photo_unit' && !$state->expires_at->isPast()) {
            $context = $state->context;
            $context['pending'][] = ['type' => $type, 'value' => $value, 'media_sid' => $mediaSid];
            $state->update(['context' => $context, 'expires_at' => now()->addMinutes(30)]);
        } else {
            // Any other active flow (or none) is superseded — a photo-derived
            // unit question takes priority, same rule as text trigger phrases.
            WhatsappConversationState::updateOrCreate(
                ['user_id' => $userId],
                [
                    'flow'       => 'awaiting_photo_unit',
                    'step'       => 'awaiting_unit',
                    'context'    => ['pending' => [['type' => $type, 'value' => $value, 'media_sid' => $mediaSid]]],
                    'expires_at' => now()->addMinutes(30),
                ]
            );
        }

        return self::promptFor($type, $value);
    }

    public function handle(WhatsappConversationState $state, string $body): array
    {
        $body = trim($body);
        $context = $state->context;
        $pending = $context['pending'] ?? [];

        if (empty($pending)) {
            $state->delete();
            return ['reply' => "Nothing pending — let's move on.", 'done' => true];
        }

        $current = $pending[0];

        if (strtoupper($body) === 'SKIP') {
            array_shift($pending);
            return $this->advanceOrFinish($state, $pending, "OK, skipped that one.");
        }

        $unit = $this->parseUnit($current['type'], $body);

        if ($unit === null) {
            return ['reply' => "I didn't catch that — " . self::promptFor($current['type'], $current['value']), 'done' => false];
        }

        $this->save($state->user_id, $current, $unit);

        array_shift($pending);
        return $this->advanceOrFinish($state, $pending, $this->confirmationText($current, $unit));
    }

    private function advanceOrFinish(WhatsappConversationState $state, array $remainingPending, string $prefixMessage): array
    {
        if (empty($remainingPending)) {
            $state->delete();
            return ['reply' => $prefixMessage, 'done' => true];
        }

        $next = $remainingPending[0];
        $state->update(['context' => ['pending' => $remainingPending], 'expires_at' => now()->addMinutes(30)]);

        return ['reply' => $prefixMessage . "\n\n" . self::promptFor($next['type'], $next['value']), 'done' => false];
    }

    private function parseUnit(string $type, string $body): ?string
    {
        $body = strtolower($body);

        return match ($type) {
            'sugar' => match (true) {
                (bool) preg_match('/mmol/', $body) => 'mmol_l',
                (bool) preg_match('/mg/', $body)    => 'mg_dl',
                default => null,
            },
            'temperature' => match (true) {
                (bool) preg_match('/\bf\b|fahren/', $body) => 'fahrenheit',
                (bool) preg_match('/\bc\b|celsius/', $body) => 'celsius',
                default => null,
            },
            'weight' => match (true) {
                (bool) preg_match('/lb/', $body) => 'lbs',
                (bool) preg_match('/kg/', $body) => 'kg',
                default => null,
            },
            default => null,
        };
    }

    private function save(int $userId, array $item, string $unit): void
    {
        $fields = match ($item['type']) {
            'sugar' => [
                'type' => 'sugar',
                'sugar_value' => $item['value'],
                'sugar_unit' => $unit,
                'is_outlier' => !VitalsPlausibilityChecker::isPlausibleSugar($item['value'], $unit),
            ],
            'temperature' => [
                'type' => 'temperature',
                'temperature_value' => $item['value'],
                'temperature_unit' => $unit,
                'is_outlier' => !VitalsPlausibilityChecker::isPlausibleTemperature($item['value'], $unit),
            ],
            'weight' => (function () use ($userId, $item, $unit) {
                $last = VitalsReading::where('user_id', $userId)->where('type', 'weight')
                    ->whereNotNull('weight_value')->latest('recorded_at')->first();
                return [
                    'type' => 'weight',
                    'weight_value' => $item['value'],
                    'weight_unit' => $unit,
                    'is_outlier' => !VitalsPlausibilityChecker::isPlausibleWeight($item['value'], $unit, $last?->weight_value, $last?->weight_unit),
                ];
            })(),
        };

        VitalsReading::create(array_merge($fields, [
            'user_id' => $userId,
            'recorded_via' => 'whatsapp',
            'media_sid' => $item['media_sid'],
            'recorded_at' => now(),
        ]));
    }

    private static function promptFor(string $type, float $value): string
    {
        return match ($type) {
            'sugar' => "I saw a sugar reading of {$value} — is your meter in mg/dL or mmol/L?",
            'temperature' => "I saw a temperature reading of {$value} — is that °C or °F?",
            'weight' => "I saw a weight reading of {$value} — is that kg or lbs?",
        };
    }

    private function confirmationText(array $item, string $unit): string
    {
        $unitLabel = match ($item['type']) {
            'sugar' => $unit === 'mg_dl' ? 'mg/dL' : 'mmol/L',
            'temperature' => $unit === 'celsius' ? '°C' : '°F',
            'weight' => $unit,
        };
        return "✅ " . ucfirst($item['type']) . " recorded: {$item['value']} {$unitLabel}";
    }
}