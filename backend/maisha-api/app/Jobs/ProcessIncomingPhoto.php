<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\MedicationExtractionReview;
use App\Models\VitalsReading;
use App\Services\WhatsAppService;
use App\Services\MedicationVisionService;
use App\Services\MedicalImageClassifierService;
use App\Services\VitalsDeviceVisionService;
use App\Services\VitalsPlausibilityChecker;
use App\Services\AwaitingPhotoUnitFlow;
use App\Services\PendingVitalsCorrectionFlow;
use Throwable;

class ProcessIncomingPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public string $mediaUrl,
        public string $mediaSid,
        public int $userId,
        public string $senderPhone,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (!$user) {
            Log::warning('ProcessIncomingPhoto: user not found', ['user_id' => $this->userId]);
            return;
        }

        $vision = new MedicationVisionService();

        $rawBytes = $vision->downloadTwilioMedia($this->mediaUrl);
        if (!$rawBytes) {
            return;
        }

        $jpegBytes = $vision->downscale($rawBytes);
        if (!$jpegBytes) {
            return;
        }

        $classifier = new MedicalImageClassifierService();
        $classification = $classifier->classify($jpegBytes);

        if (!$classification) {
            $this->sendWhatsAppReply(
                $this->senderPhone,
                "Having trouble reaching the image service right now. Try again in a minute?"
            );
            return;
        }

        $category = $classification['category'] ?? 'unclear';

        Log::info('Photo classified', [
            'media_sid' => $this->mediaSid,
            'category' => $category,
            'confidence' => $classification['confidence'] ?? null,
        ]);

        switch ($category) {
            case 'vitals_device':
                $this->handleVitalsDevicePhoto($jpegBytes, $user);
                break;

            case 'medication_label':
            case 'handwritten_prescription':
                $this->handleMedicationExtraction($jpegBytes, $user);
                break;

            case 'lab_report':
                $this->sendWhatsAppReply(
                    $this->senderPhone,
                    "That's a lab report. Full lab reports aren't supported yet, but it's on the way."
                );
                break;

            case 'not_medical':
                $this->sendWhatsAppReply(
                    $this->senderPhone,
                    "That doesn't look like a medical photo. Did you mean to send something else?"
                );
                break;

            default:
                $this->sendWhatsAppReply(
                    $this->senderPhone,
                    "Not sure what this shows. Is it a medication, a BP/sugar/temperature/weight reading, or a lab report?"
                );
        }
    }

    /**
     * Route: vitals_device. Loops over $result['readings'] — the extraction
     * service returns an array (one entry per distinct device visible in
     * frame) rather than a single flat object, since a photo can contain
     * multiple devices at once (confirmed via real testing: glucometer +
     * BP monitor together). Each reading is saved/replied to independently.
     *
     * Handles five reading types per pass: BP, sugar, temperature, weight,
     * and pulse oximeter (SpO2). Anything else the vision service returns (e.g. a pulse
     * oximeter/SpO2 reading — device_type recognized by the vision
     * service, but with no supported field for us to save it under) is
     * explicitly called out as unsupported rather than silently dropped.
     * See the trailing branch at the end of the per-reading loop.
     *
     * If any reading comes back unreadable on the first pass, we retry the
     * whole extraction once and patch in any readings that succeed on the
     * second attempt (observed non-determinism in the vision model).
     *
     * Each VitalsReading::create() call is individually wrapped in
     * try/catch so one failed insert never blocks the final reply.
     *
     * Uses AwaitingPhotoUnitFlow for sugar, temperature, and weight when
     * the unit is missing. Every readable reading is saved immediately —
     * there's no gate on the save anymore. Readings that meet
     * PendingVitalsCorrectionFlow's criteria (BP always, anything from a
     * photo where a retry fired, or anything below the confidence floor)
     * are ALSO collected into $needsCorrection as they're saved; once the
     * loop finishes, that whole batch is enqueued as a single combined
     * "here's what to double check" message rather than one message per
     * reading. Readings that don't meet those criteria just get a normal
     * "X saved" line, same as always.
     *
     * Reply copy is kept short and non-repetitive on purpose — a user
     * reading this on their phone should get the gist in one glance,
     * not re-read the same disclaimer for every reading in a multi-device
     * photo.
     */
    private function handleVitalsDevicePhoto(string $jpegBytes, User $user): void
    {
        $vitalsVision = new VitalsDeviceVisionService();
        $result = $vitalsVision->extract($jpegBytes);

        Log::info('Vitals extraction raw result', ['media_sid' => $this->mediaSid, 'result' => $result]);

        $readings = $result['readings'] ?? [];

        if (!$result || empty($readings)) {
            $this->sendWhatsAppReply(
                $this->senderPhone,
                "Couldn't get a clear reading from that photo. Mind typing the number instead?"
            );
            return;
        }

        $retryFired = false;

        if ($this->hasUnreadableReading($readings)) {
            Log::info('Vitals extraction had unreadable reading(s), retrying once', [
                'media_sid' => $this->mediaSid,
            ]);

            $retryFired = true;

            $retryResult = $vitalsVision->extract($jpegBytes);

            Log::info('Vitals extraction retry result', [
                'media_sid' => $this->mediaSid,
                'result' => $retryResult,
            ]);

            $readings = $this->mergeReadings($readings, $retryResult['readings'] ?? []);
        }

        $readings = $this->dedupeReadingsByType($readings);

        $savedAny = false;
        $replies = [];
        $needsCorrection = [];

        foreach ($readings as $reading) {
            if (!($reading['readable'] ?? false)) {
                $reason = $reading['reason_if_unreadable'] ?? "Couldn't read that clearly";
                $replies[] = "{$reason}. Try better lighting, or just type the number instead.";
                continue;
            }

            // BP
            if (($reading['systolic'] ?? null) !== null && ($reading['diastolic'] ?? null) !== null) {
                $isOutlier = !VitalsPlausibilityChecker::isPlausibleBp(
                    $reading['systolic'],
                    $reading['diastolic'],
                    $reading['pulse'] ?? null
                );

                try {
                    $saved = VitalsReading::create([
                        'user_id'      => $user->id,
                        'type'         => 'bp',
                        'systolic'     => $reading['systolic'],
                        'diastolic'    => $reading['diastolic'],
                        'pulse'        => $reading['pulse'] ?? null,
                        'is_outlier'   => $isOutlier,
                        'recorded_via' => 'whatsapp',
                        'media_sid'    => $this->mediaSid,
                        'recorded_at'  => now(),
                    ]);

                    if (PendingVitalsCorrectionFlow::requiresCorrection($reading, $retryFired)) {
                        $needsCorrection[] = ['reading' => $reading, 'vitals_reading_id' => $saved->id];
                    } else {
                        $pulseNote = ($reading['pulse'] ?? null) ? ", pulse {$reading['pulse']}" : '';
                        $outlierNote = $isOutlier ? " (a bit outside the usual range, worth a re-check)" : '';
                        $replies[] = "BP saved: {$reading['systolic']}/{$reading['diastolic']}{$pulseNote}{$outlierNote}";
                    }

                    $savedAny = true;
                } catch (UniqueConstraintViolationException $e) {
                    Log::warning('BP reading already saved for this media_sid, skipping duplicate', [
                        'media_sid' => $this->mediaSid,
                    ]);
                    $replies[] = "BP already saved: {$reading['systolic']}/{$reading['diastolic']}";
                    $savedAny = true;
                } catch (Throwable $e) {
                    Log::error('Failed to save BP reading', [
                        'media_sid' => $this->mediaSid,
                        'error' => $e->getMessage(),
                    ]);
                    $replies[] = "Got your BP reading but couldn't save it. Mind trying again?";
                }

                continue;
            }

            // Sugar
            if (($reading['sugar_value'] ?? null) !== null) {
                if (!($reading['sugar_unit'] ?? null)) {
                    $replies[] = AwaitingPhotoUnitFlow::enqueue(
                        $user->id,
                        'sugar',
                        (float) $reading['sugar_value'],
                        $this->mediaSid
                    );
                    continue;
                }

                $isOutlier = !VitalsPlausibilityChecker::isPlausibleSugar(
                    (float) $reading['sugar_value'],
                    $reading['sugar_unit']
                );

                try {
                    $saved = VitalsReading::create([
                        'user_id'      => $user->id,
                        'type'         => 'sugar',
                        'sugar_value'  => $reading['sugar_value'],
                        'sugar_unit'   => $reading['sugar_unit'],
                        'is_outlier'   => $isOutlier,
                        'recorded_via' => 'whatsapp',
                        'media_sid'    => $this->mediaSid,
                        'recorded_at'  => now(),
                    ]);

                    if (PendingVitalsCorrectionFlow::requiresCorrection($reading, $retryFired)) {
                        $needsCorrection[] = ['reading' => $reading, 'vitals_reading_id' => $saved->id];
                    } else {
                        $outlierNote = $isOutlier ? " (a bit outside the usual range, worth a re-check)" : '';
                        $replies[] = "Sugar saved: {$reading['sugar_value']} {$reading['sugar_unit']}{$outlierNote}";
                    }

                    $savedAny = true;
                } catch (UniqueConstraintViolationException $e) {
                    Log::warning('Sugar reading already saved for this media_sid, skipping duplicate', [
                        'media_sid' => $this->mediaSid,
                    ]);
                    $replies[] = "Sugar already saved: {$reading['sugar_value']} {$reading['sugar_unit']}";
                    $savedAny = true;
                } catch (Throwable $e) {
                    Log::error('Failed to save sugar reading', [
                        'media_sid' => $this->mediaSid,
                        'error' => $e->getMessage(),
                    ]);
                    $replies[] = "Got your sugar reading but couldn't save it. Mind trying again?";
                }

                continue;
            }

            // Temperature
            if (($reading['temperature_value'] ?? null) !== null) {
                if (!($reading['temperature_unit'] ?? null)) {
                    $replies[] = AwaitingPhotoUnitFlow::enqueue(
                        $user->id,
                        'temperature',
                        (float) $reading['temperature_value'],
                        $this->mediaSid
                    );
                    continue;
                }

                $isOutlier = !VitalsPlausibilityChecker::isPlausibleTemperature(
                    (float) $reading['temperature_value'],
                    $reading['temperature_unit']
                );

                try {
                    $saved = VitalsReading::create([
                        'user_id'           => $user->id,
                        'type'              => 'temperature',
                        'temperature_value' => $reading['temperature_value'],
                        'temperature_unit'  => $reading['temperature_unit'],
                        'is_outlier'        => $isOutlier,
                        'recorded_via'      => 'whatsapp',
                        'media_sid'         => $this->mediaSid,
                        'recorded_at'       => now(),
                    ]);

                    if (PendingVitalsCorrectionFlow::requiresCorrection($reading, $retryFired)) {
                        $needsCorrection[] = ['reading' => $reading, 'vitals_reading_id' => $saved->id];
                    } else {
                        $unitSymbol = $reading['temperature_unit'] === 'celsius' ? '°C' : '°F';
                        $outlierNote = $isOutlier ? " (a bit outside the usual range, worth a re-check)" : '';
                        $replies[] = "Temperature saved: {$reading['temperature_value']}{$unitSymbol}{$outlierNote}";
                    }

                    $savedAny = true;
                } catch (UniqueConstraintViolationException $e) {
                    Log::warning('Temperature reading already saved for this media_sid, skipping duplicate', [
                        'media_sid' => $this->mediaSid,
                    ]);
                    $unitSymbol = $reading['temperature_unit'] === 'celsius' ? '°C' : '°F';
                    $replies[] = "Temperature already saved: {$reading['temperature_value']}{$unitSymbol}";
                    $savedAny = true;
                } catch (Throwable $e) {
                    Log::error('Failed to save temperature reading', [
                        'media_sid' => $this->mediaSid,
                        'error' => $e->getMessage(),
                    ]);
                    $replies[] = "Got your temperature but couldn't save it. Mind trying again?";
                }

                continue;
            }

            // Weight
            if (($reading['weight_value'] ?? null) !== null) {
                if (!($reading['weight_unit'] ?? null)) {
                    $replies[] = AwaitingPhotoUnitFlow::enqueue(
                        $user->id,
                        'weight',
                        (float) $reading['weight_value'],
                        $this->mediaSid
                    );
                    continue;
                }

                $lastWeight = VitalsReading::where('user_id', $user->id)
                    ->where('type', 'weight')
                    ->whereNotNull('weight_value')
                    ->latest('recorded_at')
                    ->first();

                $isPlausible = VitalsPlausibilityChecker::isPlausibleWeight(
                    (float) $reading['weight_value'],
                    $reading['weight_unit'],
                    $lastWeight?->weight_value,
                    $lastWeight?->weight_unit
                );

                try {
                    $saved = VitalsReading::create([
                        'user_id'      => $user->id,
                        'type'         => 'weight',
                        'weight_value' => $reading['weight_value'],
                        'weight_unit'  => $reading['weight_unit'],
                        'is_outlier'   => !$isPlausible,
                        'recorded_via' => 'whatsapp',
                        'media_sid'    => $this->mediaSid,
                        'recorded_at'  => now(),
                    ]);

                    if (PendingVitalsCorrectionFlow::requiresCorrection($reading, $retryFired)) {
                        $needsCorrection[] = ['reading' => $reading, 'vitals_reading_id' => $saved->id];
                    } else {
                        $note = '';
                        if (!$isPlausible) {
                            $note = $lastWeight
                                ? " (bigger jump than usual, worth a re-check)"
                                : " (looks unusually high or low, worth a re-check)";
                        }
                        $replies[] = "Weight saved: {$reading['weight_value']} {$reading['weight_unit']}{$note}";
                    }

                    $savedAny = true;
                } catch (UniqueConstraintViolationException $e) {
                    Log::warning('Weight reading already saved for this media_sid, skipping duplicate', [
                        'media_sid' => $this->mediaSid,
                    ]);
                    $replies[] = "Weight already saved: {$reading['weight_value']} {$reading['weight_unit']}";
                    $savedAny = true;
                } catch (Throwable $e) {
                    Log::error('Failed to save weight reading', [
                        'media_sid' => $this->mediaSid,
                        'error' => $e->getMessage(),
                    ]);
                    $replies[] = "Got your weight but couldn't save it. Mind trying again?";
                }

                continue;
            }

            // Pulse oximeter (SpO2)
            //
            // No unit branch needed — SpO2 is always a percentage, unlike
            // sugar/temperature/weight which can arrive in different
            // units the vision service must report separately.
            //
            // Treated like BP for correction purposes (always flagged,
            // see PendingVitalsCorrectionFlow::requiresCorrection): this
            // is a newly wired-up device with no track record yet for
            // how reliably the vision model reads its display. Revisit
            // once there's real extraction data to judge accuracy from,
            // the same way BP's "always confirm" was justified by an
            // observed retry producing two different high-confidence
            // reads of the same photo.
            if (($reading['spo2_value'] ?? null) !== null) {
                $isOutlier = !VitalsPlausibilityChecker::isPlausibleSpo2(
                    (int) $reading['spo2_value'],
                    $reading['pulse'] ?? null
                );

                try {
                    $saved = VitalsReading::create([
                        'user_id'      => $user->id,
                        'type'         => 'oximeter',
                        'spo2_value'   => $reading['spo2_value'],
                        'pulse'        => $reading['pulse'] ?? null,
                        'is_outlier'   => $isOutlier,
                        'recorded_via' => 'whatsapp',
                        'media_sid'    => $this->mediaSid,
                        'recorded_at'  => now(),
                    ]);

                    if (PendingVitalsCorrectionFlow::requiresCorrection($reading, $retryFired)) {
                        $needsCorrection[] = ['reading' => $reading, 'vitals_reading_id' => $saved->id];
                    } else {
                        $pulseNote = ($reading['pulse'] ?? null) ? ", pulse {$reading['pulse']}" : '';
                        $outlierNote = $isOutlier ? " (a bit outside the usual range, worth a re-check)" : '';
                        $replies[] = "SpO2 saved: {$reading['spo2_value']}%{$pulseNote}{$outlierNote}";
                    }

                    $savedAny = true;
                } catch (UniqueConstraintViolationException $e) {
                    Log::warning('SpO2 reading already saved for this media_sid, skipping duplicate', [
                        'media_sid' => $this->mediaSid,
                    ]);
                    $replies[] = "SpO2 already saved: {$reading['spo2_value']}%";
                    $savedAny = true;
                } catch (Throwable $e) {
                    Log::error('Failed to save SpO2 reading', [
                        'media_sid' => $this->mediaSid,
                        'error' => $e->getMessage(),
                    ]);
                    $replies[] = "Got your SpO2 reading but couldn't save it. Mind trying again?";
                }

                continue;
            }

            // FIX: unrecognized reading type — a readable reading whose
            // fields don't match any of the five supported types above
            // (BP/sugar/temperature/weight/oximeter). This was the exact
            // gap that let a pulse-oximeter reading vanish before it had
            // its own branch: it fell through every check with no save,
            // no reply, and no log — the device was simply never
            // mentioned, which is why a 3-device photo only ever
            // produced 2 acknowledgments. Any FUTURE device type (ECG,
            // respiratory rate, etc.) will hit this same branch until it
            // gets its own — say so explicitly instead of staying
            // silent; nothing is saved here since there's no supported
            // field/column for it yet.
            $deviceLabel = $reading['device_type'] ?? 'that device';
            Log::info('Readable reading did not match any supported vitals type, not saved', [
                'media_sid'   => $this->mediaSid,
                'device_type' => $reading['device_type'] ?? null,
                'reading'     => $reading,
            ]);
            $replies[] = "Got a reading from {$deviceLabel} but I can't record that type yet — right now I support blood pressure, blood sugar, temperature, weight, and pulse oximeter (SpO2).";
        }

        if (!empty($needsCorrection)) {
            $replies[] = PendingVitalsCorrectionFlow::enqueue($user->id, $needsCorrection, $this->mediaSid);
        }

        if (!$savedAny && empty($replies)) {
            $this->sendWhatsAppReply(
                $this->senderPhone,
                "Couldn't get a clear reading from that photo. Mind typing the number instead?"
            );
            return;
        }

        $this->sendWhatsAppReply($this->senderPhone, implode("\n", $replies));
    }

    /**
     * True if at least one reading in the set is marked unreadable.
     */
    private function hasUnreadableReading(array $readings): bool
    {
        foreach ($readings as $reading) {
            if (!($reading['readable'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Patch unreadable readings from the first pass with a matching
     * (same device_type) readable reading from the retry pass. Readings
     * that were already readable on the first pass are left untouched.
     * If the retry doesn't have a matching readable reading for a
     * device_type, the original (unreadable) reading is kept as-is.
     */
    private function mergeReadings(array $original, array $retry): array
    {
        foreach ($original as $i => $reading) {
            if ($reading['readable'] ?? false) {
                continue;
            }

            foreach ($retry as $retryReading) {
                $sameDevice = ($retryReading['device_type'] ?? null) === ($reading['device_type'] ?? null);

                if ($sameDevice && ($retryReading['readable'] ?? false)) {
                    $original[$i] = $retryReading;
                    break;
                }
            }
        }

        return $original;
    }

    /**
     * Collapses multiple readings of the SAME measurement type down to
     * one. Observed in practice on combo-device photos (thermometer +
     * pulse ox + BP cuff in one frame): the vision model can return more
     * than one entry that satisfies the same type check below — e.g. two
     * separate entries both carrying systolic/diastolic, or a sugar
     * reading duplicated once with its unit set and once without. Left
     * unhandled, each entry gets processed independently, which is what
     * produced two separate BP confirmation prompts (and a sugar reading
     * that was simultaneously auto-saved AND asked for its unit) for what
     * was actually one physical measurement.
     *
     * Type is determined the same way the save loop determines it (first
     * matching field wins: BP, then sugar, then temperature, then weight,
     * then 'unknown' for anything else — see readingType()), so this
     * stays consistent with how readings are actually branched on below.
     * Unreadable readings are left alone — they're not measurements yet,
     * so there's nothing to dedupe, and dropping one could hide a real
     * "couldn't read this" message the user should still see.
     *
     * Among duplicates of the same type, preference order is:
     *   1. An entry with its unit field present (a complete reading beats
     *      one still missing data — this is what fixes the sugar case).
     *   2. Higher stated confidence.
     *   3. First occurrence, as a stable fallback.
     * All other duplicates are dropped and logged, not silently ignored.
     */
    private function dedupeReadingsByType(array $readings): array
    {
        $buckets = [];
        $order = [];

        foreach ($readings as $reading) {
            if (!($reading['readable'] ?? false)) {
                $order[] = ['type' => null, 'reading' => $reading];
                continue;
            }

            $type = $this->readingType($reading);
            $order[] = ['type' => $type, 'reading' => $reading];
            $buckets[$type][] = $reading;
        }

        $chosen = [];
        foreach ($buckets as $type => $candidates) {
            if (count($candidates) === 1) {
                $chosen[$type] = $candidates[0];
                continue;
            }

            Log::warning('Multiple readings of the same type in one photo, deduping', [
                'media_sid' => $this->mediaSid,
                'type' => $type,
                'count' => count($candidates),
            ]);

            usort($candidates, function ($a, $b) {
                $aHasUnit = $this->hasUnit($a) ? 1 : 0;
                $bHasUnit = $this->hasUnit($b) ? 1 : 0;
                if ($aHasUnit !== $bHasUnit) {
                    return $bHasUnit <=> $aHasUnit;
                }

                $aConf = $a['confidence'] ?? 0;
                $bConf = $b['confidence'] ?? 0;
                return $bConf <=> $aConf;
            });

            $chosen[$type] = $candidates[0];
        }

        $result = [];
        $usedTypes = [];
        foreach ($order as $entry) {
            if ($entry['type'] === null) {
                $result[] = $entry['reading'];
                continue;
            }

            if (in_array($entry['type'], $usedTypes, true)) {
                continue;
            }

            $usedTypes[] = $entry['type'];
            $result[] = $chosen[$entry['type']];
        }

        return $result;
    }

    /**
     * Same five-type classification the save loop uses (BP, then sugar,
     * then temperature, then weight, then oximeter/SpO2). Anything that
     * matches none of those is 'unknown'. This is a real, expected
     * bucket now: dedupeReadingsByType still collapses duplicate
     * 'unknown' readings the same way it does for the other five types,
     * and the save loop's trailing branch (see the FIX comment there)
     * is what turns a surviving 'unknown' reading into an explicit
     * "can't record that yet" reply instead of silence.
     */
    private function readingType(array $reading): string
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

    private function hasUnit(array $reading): bool
    {
        if (($reading['sugar_value'] ?? null) !== null) {
            return !empty($reading['sugar_unit']);
        }
        if (($reading['temperature_value'] ?? null) !== null) {
            return !empty($reading['temperature_unit']);
        }
        if (($reading['weight_value'] ?? null) !== null) {
            return !empty($reading['weight_unit']);
        }
        return true; // BP has no unit field to be missing
    }

    private function handleMedicationExtraction(string $jpegBytes, User $user): void
    {
        $vision = new MedicationVisionService();

        $extracted = $vision->extract($jpegBytes);
        if (!$extracted) {
            return;
        }

        MedicationExtractionReview::create([
            'user_id'        => $user->id,
            'media_sid'      => $this->mediaSid,
            'extracted_data' => $extracted,
            'confidence'     => $extracted['confidence'] ?? null,
            'status'         => 'pending',
        ]);

        Log::info('Medication extraction saved for review', [
            'user_id' => $user->id,
            'media_sid' => $this->mediaSid,
            'readable' => $extracted['readable'] ?? null,
            'confidence' => $extracted['confidence'] ?? null,
        ]);
    }

    private function sendWhatsAppReply(string $toPhone, string $replyText): void
    {
        $bareDigits = preg_replace('/[^0-9]/', '', $toPhone);
        app(WhatsAppService::class)->sendText($bareDigits, $replyText);
    }
}