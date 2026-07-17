<?php

namespace App\Services;

use App\Models\VitalsReading;
use App\Models\WhatsappConversationState;
use Illuminate\Support\Facades\Log;

/**
 * Lets a user correct a photo-derived reading that we're not fully
 * confident in, WITHOUT gating the save on a reply. This ONLY applies to
 * readings extracted from a device photo (see ProcessIncomingPhoto.php)
 * — manual text entry via BpCaptureFlow/SugarCaptureFlow/TemperatureCaptureFlow/
 * WeightCaptureFlow is unaffected.
 *
 * Unlike the confirmation flow this replaces, there is no "reply YES"
 * step. Every reading routed here has ALREADY been saved to the DB by
 * the time enqueue() runs. Silence is treated as confirmation: if the
 * user never replies, the saved value simply stands once the pending
 * state expires. A reply only matters when the system got something
 * wrong — it patches the already-saved row in place.
 *
 * A reading is routed here (flagged for possible correction) when:
 *  - It's a BP reading, always (real test run produced two different
 *    results from retrying the SAME photo, both at high confidence).
 *  - It's a pulse oximeter (SpO2) reading, always (newly wired-up device
 *    type, no track record yet — see the branch below).
 *  - It's a weight reading flagged as a percentage-change outlier by the
 *    caller (i.e. VitalsPlausibilityChecker::isPlausibleWeight() failed
 *    against the user's last saved weight) — this is passed in via
 *    $isOutlier rather than recomputed here, since the caller already
 *    has the last-reading context needed to compute it.
 *  - Any reading from a photo where a retry fired at all.
 *  - Any reading below a flat confidence floor (0.9).
 *
 * All flagged readings from one photo are bundled into a SINGLE
 * numbered message (see enqueue()/promptForList()) rather than one
 * message per reading — a user reading this on their phone should see
 * everything that might need a second look in one glance, and correct
 * whichever ones are wrong by number.
 */
class PendingVitalsCorrectionFlow
{
    private const CONFIDENCE_FLOOR = 0.9;
    private const FLOW = 'pending_vitals_correction';

    /**
     * Adds one or more already-saved readings to this user's pending
     * correction list and returns the combined numbered prompt for
     * whatever is now pending (existing pending items from an
     * still-active window, plus the new ones just added).
     *
     * $items is an array of ['reading' => array, 'vitals_reading_id' => int].
     */
    public static function enqueue(int $userId, array $items, string $mediaSid): string
    {
        $state = WhatsappConversationState::where('user_id', $userId)->first();

        $pending = [];
        if ($state && $state->flow === self::FLOW && !$state->expires_at->isPast()) {
            $pending = $state->context['pending'] ?? [];
        }

        // FIX: a fresh reading supersedes any not-yet-answered pending
        // correction of the SAME measurement type, instead of stacking a
        // second entry onto the list. Without this, resending/retaking a
        // photo before replying to the previous prompt silently doubled
        // up "Temperature reads..." / "BP reads..." lines — and worse,
        // it renumbered every later item out from under the user, so a
        // reply like "3. 119/81 pulse 70" (correct for the OLD list) got
        // matched against the wrong, newly-inserted item in the merged
        // list and rejected. The old VitalsReading row isn't touched —
        // it was already saved and stands as-is (silence = confirmed);
        // this only drops the stale, unanswered PROMPT for it, since the
        // new reading is what the user is now actually looking at.
        $newTypes = array_map(fn ($item) => self::readingType($item['reading']), $items);
        $pending = array_values(array_filter(
            $pending,
            function ($existing) use ($newTypes, $mediaSid) {
                $stale = in_array(self::readingType($existing['reading']), $newTypes, true);
                if ($stale) {
                    Log::info('Superseding stale pending vitals correction with fresh reading', [
                        'type'          => self::readingType($existing['reading']),
                        'old_media_sid' => $existing['media_sid'] ?? null,
                        'new_media_sid' => $mediaSid,
                    ]);
                }
                return !$stale;
            }
        ));

        foreach ($items as $item) {
            $pending[] = [
                'reading'           => $item['reading'],
                'vitals_reading_id' => $item['vitals_reading_id'],
                'media_sid'         => $mediaSid,
            ];
        }

        WhatsappConversationState::updateOrCreate(
            ['user_id' => $userId],
            [
                'flow'       => self::FLOW,
                'step'       => 'awaiting_correction',
                'context'    => ['pending' => $pending],
                'expires_at' => now()->addMinutes(30),
            ]
        );

        return self::promptForList($pending);
    }

    /**
     * Same "first field wins" classification ProcessIncomingPhoto uses
     * (BP, then sugar, then temperature, then weight) — kept identical
     * on purpose so a reading is bucketed the same way here as it was
     * when originally saved/deduped.
     */
    private static function readingType(array $reading): string
    {
        if (($reading['systolic'] ?? null) !== null && ($reading['diastolic'] ?? null) !== null) {
            return 'bp';
        }
        if (($reading['sugar_value'] ?? null) !== null) {
            return 'sugar';
        }
        if (($reading['temperature_value'] ?? null) !== null) {
            return 'temperature';
        }
        if (($reading['weight_value'] ?? null) !== null) {
            return 'weight';
        }
        if (($reading['spo2_value'] ?? null) !== null) {
            return 'oximeter';
        }
        return 'unknown';
    }

    /**
     * $isOutlier is only meaningful for weight readings — it's the
     * result of VitalsPlausibilityChecker::isPlausibleWeight() (negated)
     * computed by the caller against the user's last saved weight. It
     * defaults to false so every other call site (BP, oximeter, sugar,
     * temperature) is unaffected and doesn't need to pass it.
     */
    public static function requiresCorrection(array $reading, bool $retryFiredOnThisPhoto, bool $isOutlier = false): bool
    {
        $isBp = ($reading['systolic'] ?? null) !== null && ($reading['diastolic'] ?? null) !== null;

        if ($isBp) {
            return true;
        }

        // Same cautious-by-default treatment as BP: this is a newly
        // wired-up device type with no track record yet for extraction
        // reliability. Revisit once there's real data to judge accuracy
        // from — see the comment on the oximeter save branch in
        // ProcessIncomingPhoto.php for the full reasoning.
        $isOximeter = ($reading['spo2_value'] ?? null) !== null;

        if ($isOximeter) {
            return true;
        }

        // Weight doesn't get the blanket "always" treatment BP/oximeter
        // get (unlike those, it has an established, reliable extraction
        // path), but a percentage-change outlier is a strong-enough
        // signal on its own that it shouldn't be left as a passive note
        // the user can't act on. $isOutlier is the same check that
        // drives the "(bigger jump than usual, worth a re-check)" note
        // that used to be appended in ProcessIncomingPhoto — if it
        // fires, route to the same confirm/deny prompt BP and oximeter
        // always get, instead of just a note.
        $isWeight = ($reading['weight_value'] ?? null) !== null;

        if ($isWeight) {
            return true;
        }

        if ($retryFiredOnThisPhoto) {
            return true;
        }

        $confidence = $reading['confidence'] ?? 0;
        return $confidence < self::CONFIDENCE_FLOOR;
    }

    /**
     * Parses one or more corrections out of a single incoming message.
     * Each correction is expected as "N. value" (or "N) value" / "N: value"
     * / "N-value" / "N value") where N is the number shown in the prompt
     * list. Multiple corrections can be sent on separate lines, or
     * separated by commas/semicolons, in one message. If exactly one
     * reading is still pending, a bare value with no leading number is
     * also accepted.
     *
     * Anything successfully parsed patches the already-saved
     * VitalsReading row directly — there's nothing left to "save" for
     * the first time, only to fix.
     */
    public function handle(WhatsappConversationState $state, string $body): array
    {
        $body = trim($body);
        $context = $state->context;
        $pending = $context['pending'] ?? [];

        if (empty($pending)) {
            $state->delete();
            return ['reply' => "Nothing pending to correct.", 'done' => true];
        }

        // FIX (bug #1): the numbers shown to the user in promptForList()
        // are fixed for the lifetime of this incoming message — they
        // reflect the list as it stood when the message arrived, not
        // whatever $pending has shrunk to partway through processing it.
        // Capturing the count ONCE here (instead of re-reading
        // count($pending) inside the loop, after earlier segments have
        // already unset() entries) is what lets a message like
        // "2. 98/86\n3. 119/81 pulse 70" apply BOTH corrections instead
        // of losing "3." the moment "2." is removed from the array.
        $originalPendingCount = count($pending);

        $segments = preg_split('/[\r\n,;]+/', $body);
        $applied = [];
        $unmatched = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            [$index, $valueText] = $this->parseSegment($segment, $originalPendingCount);

            // Number-based match failed (stale/out-of-range number, e.g.
            // "3." left over from an earlier prompt whose numbering has
            // since shifted). Before giving up, check whether the VALUE
            // itself unambiguously matches exactly one pending item by
            // format — a BP-shaped "119/81 pulse 70" can't accidentally
            // match a temperature/sugar/weight slot, so if only one
            // pending item is BP, that's clearly the intended target
            // regardless of which number the user actually typed.
            if ($index === null) {
                $index = $this->matchByShape($valueText, $pending);
            }

            if ($index === null || !array_key_exists($index, $pending)) {
                $unmatched[] = $segment;
                continue;
            }

            $item = $pending[$index];
            $corrected = $this->tryApplyCorrection($item['reading'], $valueText);

            if ($corrected === null) {
                $unmatched[] = $segment;
                continue;
            }

            $this->applyUpdate($item['vitals_reading_id'], $corrected);
            $applied[] = $this->formatValue($corrected);

            unset($pending[$index]);
        }

        $pending = array_values($pending);

        if (!empty($pending)) {
            $context['pending'] = $pending;
            $state->update(['context' => $context, 'expires_at' => now()->addMinutes(30)]);
        } else {
            $state->delete();
        }

        $replyParts = [];

        if (!empty($applied)) {
            $replyParts[] = "Updated:\n" . implode("\n", array_map(fn ($v) => "\u{2713} {$v}", $applied));
        }

        if (!empty($unmatched)) {
            $replyParts[] = "Didn't catch: " . implode('; ', $unmatched)
                . ". Use the number shown, e.g. \"1. 120/80\".";
        }

        if (empty($replyParts)) {
            $replyParts[] = "Didn't catch that. Use the number shown, e.g. \"1. 120/80\".";
        }

        return ['reply' => implode("\n\n", $replyParts), 'done' => empty($pending)];
    }

    /**
     * Extracts a leading "N<sep><whitespace>" index prefix, where sep is
     * one of . ) : - and is itself optional as long as whitespace
     * follows the number. The number is only treated as an index if it
     * falls within the current pending range — this is what keeps a
     * bare BP value like "120/80" (no dot, no valid index range) or a
     * decimal value like "36.5" (no space after the dot) from being
     * misread as "index 120" or "index 36".
     *
     * $pendingCount is the count captured ONCE at the start of handle()
     * for the whole incoming message — see the FIX comment there.
     */
    private function parseSegment(string $segment, int $pendingCount): array
    {
        if (preg_match('/^\s*(\d{1,2})[.\):-]?\s+(.+)$/', $segment, $m)) {
            $n = (int) $m[1];
            $value = trim($m[2]);

            if ($n >= 1 && $n <= $pendingCount) {
                return [$n - 1, $value];
            }

            // FIX (bug #2): the message HAS a leading number, it's just
            // stale or out of range for this batch (e.g. the user is
            // replying "3. 119/81 pulse 70" to a re-sent prompt where
            // only one item, re-numbered "1.", is actually still
            // pending). If exactly one item is pending, treat it as the
            // target and use the value with the numeric prefix already
            // stripped — NOT the raw segment, which still has "3. " glued
            // to the front and will fail every value regex in
            // tryApplyCorrection().
            if ($pendingCount === 1) {
                return [0, $value];
            }

            // Prefix present but the number doesn't match anything in
            // the current list (more than one item pending, so we can't
            // just assume "the one item"). Still return the STRIPPED
            // value rather than giving up here — handle() gets a chance
            // to shape-match it against pending items by format before
            // finally rejecting it. See matchByShape().
            return [null, $value];
        }

        if ($pendingCount === 1) {
            return [0, $segment];
        }

        return [null, $segment];
    }

    /**
     * Fallback for when the leading number doesn't identify any pending
     * item (typically stale numbering from an earlier, differently-sized
     * prompt). If the value's FORMAT is only accepted by exactly one
     * still-pending item's type — e.g. only a BP reading's regex accepts
     * an "N/N[, pulse N]" shape — that's the correction target, no
     * matter what number the user actually typed. If it's accepted by
     * zero or by more than one pending item, this stays ambiguous and
     * the caller falls through to "didn't catch."
     */
    private function matchByShape(string $valueText, array $pending): ?int
    {
        $matches = [];

        foreach ($pending as $idx => $item) {
            if ($this->tryApplyCorrection($item['reading'], $valueText) !== null) {
                $matches[] = $idx;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function tryApplyCorrection(array $reading, string $valueText): ?array
    {
        $isBp = ($reading['systolic'] ?? null) !== null && ($reading['diastolic'] ?? null) !== null;

        if ($isBp) {
            // Matches "119/81", "119/81 pulse 70", "119/81, pulse: 70", etc.
            // Systolic/diastolic are required; a trailing pulse value is
            // optional and only overwrites the existing pulse if given.
            if (preg_match('/^\s*(\d{2,3})\s*[\/\s]\s*(\d{2,3})\s*(?:[,\s]*pulse\s*[:\-]?\s*(\d{2,3}))?\s*$/i', $valueText, $m)) {
                $reading['systolic'] = (int) $m[1];
                $reading['diastolic'] = (int) $m[2];
                if (!empty($m[3])) {
                    $reading['pulse'] = (int) $m[3];
                }
                return $reading;
            }
            return null;
        }

        if (($reading['spo2_value'] ?? null) !== null) {
            // Matches "98", "98%", "98 pulse 72", "98%, pulse: 72", etc.
            // SpO2 is required; a trailing pulse value is optional and
            // only overwrites the existing pulse if given — same
            // convention as the BP branch above, since both share the
            // `pulse` column.
            if (preg_match('/^\s*(\d{2,3})\s*%?\s*(?:[,\s]*pulse\s*[:\-]?\s*(\d{2,3}))?\s*$/i', $valueText, $m)) {
                $reading['spo2_value'] = (int) $m[1];
                if (!empty($m[2])) {
                    $reading['pulse'] = (int) $m[2];
                }
                return $reading;
            }
            return null;
        }

        if (($reading['sugar_value'] ?? null) !== null) {
            if (preg_match('/^\s*(\d+(\.\d)?)\s*$/', $valueText, $m)) {
                $reading['sugar_value'] = (float) $m[1];
                return $reading;
            }
            return null;
        }

        if (($reading['temperature_value'] ?? null) !== null) {
            if (preg_match('/^\s*(\d{2,3}(\.\d)?)\s*$/', $valueText, $m)) {
                $reading['temperature_value'] = (float) $m[1];
                return $reading;
            }
            return null;
        }

        if (($reading['weight_value'] ?? null) !== null) {
            if (preg_match('/^\s*(\d{1,3}(\.\d)?)\s*$/', $valueText, $m)) {
                $reading['weight_value'] = (float) $m[1];
                return $reading;
            }
            return null;
        }

        return null;
    }

    /**
     * Patches the already-saved VitalsReading row with the corrected
     * value(s) and recomputes is_outlier off the corrected number rather
     * than the original photo-derived one. Unit fields are never part of
     * a correction (AwaitingPhotoUnitFlow owns that) so they're pulled
     * from the existing record rather than the reading array.
     */
    private function applyUpdate(int $vitalsReadingId, array $corrected): void
    {
        $record = VitalsReading::find($vitalsReadingId);
        if (!$record) {
            return;
        }

        $isBp = ($corrected['systolic'] ?? null) !== null && ($corrected['diastolic'] ?? null) !== null;

        if ($isBp) {
            $pulse = $corrected['pulse'] ?? $record->pulse;
            $record->update([
                'systolic'   => $corrected['systolic'],
                'diastolic'  => $corrected['diastolic'],
                'pulse'      => $pulse,
                'is_outlier' => !VitalsPlausibilityChecker::isPlausibleBp(
                    $corrected['systolic'], $corrected['diastolic'], $pulse
                ),
            ]);
            return;
        }

        if (($corrected['spo2_value'] ?? null) !== null) {
            $pulse = $corrected['pulse'] ?? $record->pulse;
            $record->update([
                'spo2_value' => $corrected['spo2_value'],
                'pulse'      => $pulse,
                'is_outlier' => !VitalsPlausibilityChecker::isPlausibleSpo2(
                    $corrected['spo2_value'], $pulse
                ),
            ]);
            return;
        }

        if (($corrected['sugar_value'] ?? null) !== null) {
            $record->update([
                'sugar_value' => $corrected['sugar_value'],
                'is_outlier'  => !VitalsPlausibilityChecker::isPlausibleSugar(
                    $corrected['sugar_value'], $record->sugar_unit
                ),
            ]);
            return;
        }

        if (($corrected['temperature_value'] ?? null) !== null) {
            $record->update([
                'temperature_value' => $corrected['temperature_value'],
                'is_outlier'        => !VitalsPlausibilityChecker::isPlausibleTemperature(
                    $corrected['temperature_value'], $record->temperature_unit
                ),
            ]);
            return;
        }

        if (($corrected['weight_value'] ?? null) !== null) {
            $last = VitalsReading::where('user_id', $record->user_id)
                ->where('type', 'weight')
                ->where('id', '!=', $record->id)
                ->whereNotNull('weight_value')
                ->latest('recorded_at')
                ->first();

            $record->update([
                'weight_value' => $corrected['weight_value'],
                'is_outlier'   => !VitalsPlausibilityChecker::isPlausibleWeight(
                    $corrected['weight_value'], $record->weight_unit, $last?->weight_value, $last?->weight_unit
                ),
            ]);
        }
    }

    /**
     * One line per pending item, numbered, plus a single shared
     * instruction line at the end — not repeated per item.
     */
    private static function promptForList(array $pending): string
    {
        $lines = [];
        foreach (array_values($pending) as $i => $item) {
            $lines[] = ($i + 1) . '. ' . self::describeReading($item['reading']);
        }

        $lines[] = '';
        $lines[] = 'Reply with the number and correct value for any that are wrong (e.g. "1. 120/80"). No reply needed if they all look right.';

        return implode("\n", $lines);
    }

    private static function describeReading(array $reading): string
    {
        $isBp = ($reading['systolic'] ?? null) !== null && ($reading['diastolic'] ?? null) !== null;

        if ($isBp) {
            $pulseNote = ($reading['pulse'] ?? null) ? ", pulse {$reading['pulse']}" : '';
            return "Blood pressure (BP) reads: Got {$reading['systolic']}/{$reading['diastolic']}{$pulseNote} from your photo. Give the correct value if incorrect.";
        }

        if (($reading['sugar_value'] ?? null) !== null) {
            $unitLabel = ($reading['sugar_unit'] ?? null) === 'mmol_l' ? 'mmol/L' : 'mg/dL';
            return "Sugar reads: Got {$reading['sugar_value']} {$unitLabel} from your photo. Give the correct value if incorrect.";
        }

        if (($reading['spo2_value'] ?? null) !== null) {
            $pulseNote = ($reading['pulse'] ?? null) ? ", pulse {$reading['pulse']}" : '';
            return "Pulse oximeter (SpO2) reads: Got {$reading['spo2_value']}%{$pulseNote} from your photo. Give the correct value if incorrect.";
        }

        if (($reading['temperature_value'] ?? null) !== null) {
            $unitSymbol = ($reading['temperature_unit'] ?? null) === 'fahrenheit' ? '°F' : '°C';
            return "Temperature reads: Got {$reading['temperature_value']}{$unitSymbol} from your photo. Give the correct value if incorrect.";
        }

        if (($reading['weight_value'] ?? null) !== null) {
            return "Weight reads: Got {$reading['weight_value']} {$reading['weight_unit']} from your photo. Give the correct value if incorrect.";
        }

        return "Reading: not fully sure I got this right. Give the correct value if incorrect.";
    }

    private function formatValue(array $reading): string
    {
        $isBp = ($reading['systolic'] ?? null) !== null && ($reading['diastolic'] ?? null) !== null;

        if ($isBp) {
            return "{$reading['systolic']}/{$reading['diastolic']} BP";
        }
        if (($reading['sugar_value'] ?? null) !== null) {
            $unitLabel = ($reading['sugar_unit'] ?? null) === 'mmol_l' ? 'mmol/L' : 'mg/dL';
            return "{$reading['sugar_value']} {$unitLabel} sugar";
        }
        if (($reading['spo2_value'] ?? null) !== null) {
            $pulseNote = ($reading['pulse'] ?? null) ? ", pulse {$reading['pulse']}" : '';
            return "{$reading['spo2_value']}% SpO2{$pulseNote}";
        }
        if (($reading['temperature_value'] ?? null) !== null) {
            $unitSymbol = ($reading['temperature_unit'] ?? null) === 'fahrenheit' ? '°F' : '°C';
            return "{$reading['temperature_value']}{$unitSymbol} temperature";
        }
        if (($reading['weight_value'] ?? null) !== null) {
            return "{$reading['weight_value']} {$reading['weight_unit']} weight";
        }
        return "your reading";
    }
}