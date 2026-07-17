<?php

namespace App\Services;

use App\Models\VitalsReading;
use App\Models\WhatsappConversationState;

/**
 * Captures the follow-up unit reply for a photo-extracted reading whose
 * unit wasn't visible on the device display (sugar/temperature/weight —
 * BP never needs this, it has no unit ambiguity).
 *
 * Context holds two queues:
 *   'pending' => array of
 *     ['type' => 'sugar'|'temperature'|'weight', 'value' => float,
 *      'media_sid' => string, 'confidence' => ?float, 'retry_fired' => bool]
 *   'flagged' => array of items (in PendingVitalsCorrectionFlow::enqueue()
 *     item shape) whose saved reading turned out to need a correction
 *     prompt once its unit came in (weight percentage-change outlier,
 *     low confidence, or a retry having fired on the source photo).
 *
 * One pending item is asked about at a time; each answered reply pops
 * the front item, saves it, and checks whether it needs to be flagged
 * for correction. This exists specifically because a single photo can
 * produce MORE THAN ONE unit-less reading (e.g. glucometer + scale, both
 * missing an on-screen unit) — a flat one-shot flow can't represent
 * that, and now also can't gate a correction hand-off on any single
 * item, since more items may still be queued.
 *
 * IMPORTANT: this flow and PendingVitalsCorrectionFlow both key
 * WhatsappConversationState by user_id alone (one row per user, not one
 * per flow), so flagged items are NEVER handed off to
 * PendingVitalsCorrectionFlow mid-queue. They're accumulated in
 * 'flagged' and the hand-off only happens once 'pending' is fully
 * drained (see advanceOrFinish()) — enqueueing early would either wipe
 * out this flow's own state update right after, or leave the row in a
 * mixed, inconsistent state.
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
     *
     * $confidence and $retryFired are carried over from the original
     * vision-extracted reading so that, once the unit comes back and the
     * reading is saved, PendingVitalsCorrectionFlow::requiresCorrection()
     * can be evaluated with the SAME inputs it would have had if this
     * reading's unit had been on the display to begin with. Without
     * these, a missing confidence value would default to 0 inside
     * requiresCorrection() and force every unit-less reading into the
     * correction flow regardless of how confident the original
     * extraction actually was.
     */
    public static function enqueue(
        int $userId,
        string $type,
        float $value,
        string $mediaSid,
        ?float $confidence = null,
        bool $retryFired = false
    ): string {
        $state = WhatsappConversationState::where('user_id', $userId)->first();

        $newItem = [
            'type'        => $type,
            'value'       => $value,
            'media_sid'   => $mediaSid,
            'confidence'  => $confidence,
            'retry_fired' => $retryFired,
        ];

        if ($state && $state->flow === 'awaiting_photo_unit' && !$state->expires_at->isPast()) {
            $context = $state->context;
            $context['pending'][] = $newItem;
            $context['flagged'] = $context['flagged'] ?? [];
            $state->update(['context' => $context, 'expires_at' => now()->addMinutes(30)]);
        } else {
            // Any other active flow (or none) is superseded — a photo-derived
            // unit question takes priority, same rule as text trigger phrases.
            WhatsappConversationState::updateOrCreate(
                ['user_id' => $userId],
                [
                    'flow'       => 'awaiting_photo_unit',
                    'step'       => 'awaiting_unit',
                    'context'    => ['pending' => [$newItem], 'flagged' => []],
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
        $flagged = $context['flagged'] ?? [];

        if (empty($pending)) {
            $state->delete();
            return ['reply' => "Nothing pending — let's move on.", 'done' => true];
        }

        $current = $pending[0];

        if (strtoupper($body) === 'SKIP') {
            array_shift($pending);
            return $this->advanceOrFinish($state, $pending, $flagged, "OK, skipped that one.");
        }

        $unit = $this->parseUnit($current['type'], $body);

        if ($unit === null) {
            return ['reply' => "I didn't catch that — " . self::promptFor($current['type'], $current['value']), 'done' => false];
        }

        $saved = $this->save($state->user_id, $current, $unit);
        array_shift($pending);

        // Only weight is checked for a percentage-change outlier here —
        // sugar and temperature intentionally keep the same behavior
        // they've always had (saved with is_outlier set, no correction
        // prompt), matching how ProcessIncomingPhoto treats them on the
        // direct (unit-visible) path. If that scope ever expands, this
        // is the one line that needs to change.
        $isOutlier = $current['type'] === 'weight' && (bool) $saved->is_outlier;

        $readingShape = $this->toReadingShape($current, $unit);

        if (PendingVitalsCorrectionFlow::requiresCorrection($readingShape, $current['retry_fired'] ?? false, $isOutlier)) {
            $flagged[] = [
                'reading'           => $readingShape,
                'vitals_reading_id' => $saved->id,
                'media_sid'         => $current['media_sid'],
            ];

            return $this->advanceOrFinish(
                $state,
                $pending,
                $flagged,
                $this->confirmationText($current, $unit) . " — I'll flag this one for a quick double-check once we're through."
            );
        }

        return $this->advanceOrFinish($state, $pending, $flagged, $this->confirmationText($current, $unit));
    }

    /**
     * Advances to the next pending item, or — once the queue is fully
     * drained — either hands this state row off to
     * PendingVitalsCorrectionFlow (if anything was flagged along the
     * way) or deletes it as before. The hand-off reuses the SAME
     * user_id row via PendingVitalsCorrectionFlow::enqueue()'s own
     * updateOrCreate(), so $state->delete() must NOT be called in that
     * branch — doing so would destroy the correction state that call
     * just wrote.
     */
    private function advanceOrFinish(WhatsappConversationState $state, array $remainingPending, array $flagged, string $prefixMessage): array
    {
        if (empty($remainingPending)) {
            if (!empty($flagged)) {
                $correctionPrompt = PendingVitalsCorrectionFlow::enqueue(
                    $state->user_id,
                    $flagged,
                    $flagged[0]['media_sid']
                );

                return ['reply' => $prefixMessage . "\n\n" . $correctionPrompt, 'done' => true];
            }

            $state->delete();
            return ['reply' => $prefixMessage, 'done' => true];
        }

        $next = $remainingPending[0];
        $state->update([
            'context'    => ['pending' => $remainingPending, 'flagged' => $flagged],
            'expires_at' => now()->addMinutes(30),
        ]);

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

    /**
     * Now returns the created VitalsReading (instead of void) so
     * handle() has both the row's id and its computed is_outlier flag
     * available for the PendingVitalsCorrectionFlow check.
     */
    private function save(int $userId, array $item, string $unit): VitalsReading
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

        return VitalsReading::create(array_merge($fields, [
            'user_id' => $userId,
            'recorded_via' => 'whatsapp',
            'media_sid' => $item['media_sid'],
            'recorded_at' => now(),
        ]));
    }

    /**
     * Reshapes the flat {type, value, confidence, ...} pending item plus
     * the now-known unit into the same reading-array shape
     * PendingVitalsCorrectionFlow expects everywhere else (weight_value/
     * weight_unit, sugar_value/sugar_unit, etc. — see readingType()/
     * describeReading()/tryApplyCorrection() there), since the original
     * vision-extracted reading array isn't available to us here, only
     * this flow's own flattened queue item.
     */
    private function toReadingShape(array $item, string $unit): array
    {
        return match ($item['type']) {
            'sugar' => [
                'sugar_value' => $item['value'],
                'sugar_unit'  => $unit,
                'confidence'  => $item['confidence'] ?? null,
            ],
            'temperature' => [
                'temperature_value' => $item['value'],
                'temperature_unit'  => $unit,
                'confidence'        => $item['confidence'] ?? null,
            ],
            'weight' => [
                'weight_value' => $item['value'],
                'weight_unit'  => $unit,
                'confidence'   => $item['confidence'] ?? null,
            ],
        };
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