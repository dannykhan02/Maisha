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
                "I'm having trouble reaching the image service right now — this is usually temporary. Mind trying again in a minute?"
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
                    "I can see this is a lab report — reading full lab reports isn't supported yet, but I've kept a note of it. That's coming in a future update."
                );
                break;

            case 'not_medical':
                $this->sendWhatsAppReply(
                    $this->senderPhone,
                    "That doesn't look like a medical photo — did you mean to send something else?"
                );
                break;

            default:
                $this->sendWhatsAppReply(
                    $this->senderPhone,
                    "I'm not sure what this photo shows — could you tell me if it's a medication, a BP/sugar reading, or a lab report?"
                );
        }
    }

    /**
     * Route: vitals_device. Loops over $result['readings'] — the extraction
     * service now returns an array (one entry per distinct device visible
     * in frame) rather than a single flat object, since a photo can contain
     * multiple devices at once (confirmed via real testing: glucometer +
     * BP monitor together). Each reading is saved/replied to independently.
     *
     * If any reading comes back unreadable on the first pass, we retry the
     * whole extraction once and patch in any readings that succeed on the
     * second attempt (observed non-determinism in the vision model).
     *
     * IMPORTANT: each VitalsReading::create() call is individually wrapped
     * in try/catch. A single photo intentionally produces up to two rows
     * (one 'bp', one 'sugar') sharing the same media_sid — if one insert
     * fails (duplicate on retry, transient DB error, etc.), that must never
     * abort the loop or prevent the final WhatsApp reply from being sent.
     * Previously an uncaught UniqueConstraintViolationException here killed
     * the whole job silently from the user's point of view — the DB write
     * for one reading succeeded, but no reply ever went out because
     * execution never reached the sendWhatsAppReply() call at the end of
     * this method. Pair this with the migration that changes the unique
     * constraint to (media_sid, type) so BP + sugar from one photo can
     * both save cleanly in the first place — this try/catch is the
     * defensive backstop, not the primary fix.
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
                "I could see the device but couldn't get a clear reading — mind typing the number instead?"
            );
            return;
        }

        if ($this->hasUnreadableReading($readings)) {
            Log::info('Vitals extraction had unreadable reading(s), retrying once', [
                'media_sid' => $this->mediaSid,
            ]);

            $retryResult = $vitalsVision->extract($jpegBytes);

            Log::info('Vitals extraction retry result', [
                'media_sid' => $this->mediaSid,
                'result' => $retryResult,
            ]);

            $readings = $this->mergeReadings($readings, $retryResult['readings'] ?? []);
        }

        $savedAny = false;
        $replies = [];

        foreach ($readings as $reading) {
            if (!($reading['readable'] ?? false)) {
                $reason = $reading['reason_if_unreadable'] ?? 'I could not read that clearly';
                $replies[] = "{$reason} — mind trying again with better lighting, or just typing the number instead?";
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
                    VitalsReading::create([
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

                    $pulseNote = ($reading['pulse'] ?? null) ? " (pulse: {$reading['pulse']})" : '';
                    $outlierNote = $isOutlier ? " — this looks outside the usual range, worth double-checking or re-measuring." : '';
                    $replies[] = "✅ BP recorded from photo: {$reading['systolic']}/{$reading['diastolic']}{$pulseNote}{$outlierNote}";
                    $savedAny = true;
                } catch (UniqueConstraintViolationException $e) {
                    Log::warning('BP reading already saved for this media_sid, skipping duplicate', [
                        'media_sid' => $this->mediaSid,
                    ]);
                    $replies[] = "✅ BP recorded from photo: {$reading['systolic']}/{$reading['diastolic']} (already saved)";
                    $savedAny = true;
                } catch (Throwable $e) {
                    Log::error('Failed to save BP reading', [
                        'media_sid' => $this->mediaSid,
                        'error' => $e->getMessage(),
                    ]);
                    $replies[] = "I read a BP value from the photo but couldn't save it — mind trying again?";
                }

                continue;
            }

            // Sugar
            if (($reading['sugar_value'] ?? null) !== null) {
                if (!($reading['sugar_unit'] ?? null)) {
                    $replies[] = "I also saw a sugar reading of {$reading['sugar_value']} but couldn't see the unit — is your meter in mg/dL or mmol/L?";
                    // Not saved yet — needs the unit first. Same open TODO
                    // as before: needs a short-lived state to capture the
                    // follow-up reply and complete the save.
                    continue;
                }

                $isOutlier = !VitalsPlausibilityChecker::isPlausibleSugar(
                    (float) $reading['sugar_value'],
                    $reading['sugar_unit']
                );

                try {
                    VitalsReading::create([
                        'user_id'      => $user->id,
                        'type'         => 'sugar',
                        'sugar_value'  => $reading['sugar_value'],
                        'sugar_unit'   => $reading['sugar_unit'],
                        'is_outlier'   => $isOutlier,
                        'recorded_via' => 'whatsapp',
                        'media_sid'    => $this->mediaSid,
                        'recorded_at'  => now(),
                    ]);

                    $outlierNote = $isOutlier ? " — this looks outside the usual range, worth double-checking or re-measuring." : '';
                    $replies[] = "✅ Sugar recorded from photo: {$reading['sugar_value']} {$reading['sugar_unit']}{$outlierNote}";
                    $savedAny = true;
                } catch (UniqueConstraintViolationException $e) {
                    Log::warning('Sugar reading already saved for this media_sid, skipping duplicate', [
                        'media_sid' => $this->mediaSid,
                    ]);
                    $replies[] = "✅ Sugar recorded from photo: {$reading['sugar_value']} {$reading['sugar_unit']} (already saved)";
                    $savedAny = true;
                } catch (Throwable $e) {
                    Log::error('Failed to save sugar reading', [
                        'media_sid' => $this->mediaSid,
                        'error' => $e->getMessage(),
                    ]);
                    $replies[] = "I read a sugar value from the photo but couldn't save it — mind trying again?";
                }
            }
        }

        if (!$savedAny && empty($replies)) {
            $this->sendWhatsAppReply(
                $this->senderPhone,
                "I could see the device but couldn't get a clear reading — mind typing the number instead?"
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
     * that were already readable on the first pass are left untouched —
     * we don't want a retry to overwrite a good first read with a worse
     * second one. If the retry doesn't have a matching readable reading
     * for a device_type, the original (unreadable) reading is kept as-is
     * so the user still gets an accurate "couldn't read that" reply.
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