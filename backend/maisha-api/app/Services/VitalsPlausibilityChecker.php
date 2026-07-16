<?php

namespace App\Services;

/**
 * Shared "is this number physically sane" check for vitals readings,
 * used by BOTH the text-entry flows (BpCaptureFlow / SugarCaptureFlow) and
 * the device-photo capture path, so the two entry methods never silently
 * disagree on what counts as an outlier.
 *
 * NOTE: these are plausibility bounds (catch typos/misreads), NOT clinical
 * diagnostic thresholds. This service never returns a medical interpretation
 * — only a boolean "is this within a physically sane range" flag.
 *
 * Cross-checked against BpCaptureFlowTest / SugarCaptureFlowTest: systolic=5
 * and sugar=900 are both confirmed outliers in the real test suite, and both
 * fall outside these bounds — consistent, but NOT a substitute for reading
 * the actual BpCaptureFlow.php/SugarCaptureFlow.php source. Reconcile the
 * exact constants against those files before treating this as authoritative.
 */
class VitalsPlausibilityChecker
{
    // Systolic / diastolic plausibility bounds (mmHg)
    private const SYSTOLIC_MIN = 70;
    private const SYSTOLIC_MAX = 250;
    private const DIASTOLIC_MIN = 40;
    private const DIASTOLIC_MAX = 150;
    private const PULSE_MIN = 30;
    private const PULSE_MAX = 220;

    // Blood sugar plausibility bounds
    private const SUGAR_MG_DL_MIN = 40;
    private const SUGAR_MG_DL_MAX = 500;
    private const SUGAR_MMOL_L_MIN = 2.2;
    private const SUGAR_MMOL_L_MAX = 27.8;

    public static function isPlausibleBp(?int $systolic, ?int $diastolic, ?int $pulse = null): bool
    {
        if ($systolic === null || $diastolic === null) {
            return false;
        }

        if ($systolic < self::SYSTOLIC_MIN || $systolic > self::SYSTOLIC_MAX) {
            return false;
        }

        if ($diastolic < self::DIASTOLIC_MIN || $diastolic > self::DIASTOLIC_MAX) {
            return false;
        }

        // Systolic should exceed diastolic — if a photo/manual entry has this
        // backwards, it's a strong signal of a pulse/diastolic mix-up.
        if ($systolic <= $diastolic) {
            return false;
        }

        if ($pulse !== null && ($pulse < self::PULSE_MIN || $pulse > self::PULSE_MAX)) {
            return false;
        }

        return true;
    }

    public static function isPlausibleSugar(?float $value, ?string $unit): bool
    {
        if ($value === null || !in_array($unit, ['mg_dl', 'mmol_l'], true)) {
            return false;
        }

        if ($unit === 'mg_dl') {
            return $value >= self::SUGAR_MG_DL_MIN && $value <= self::SUGAR_MG_DL_MAX;
        }

        return $value >= self::SUGAR_MMOL_L_MIN && $value <= self::SUGAR_MMOL_L_MAX;
    }
}