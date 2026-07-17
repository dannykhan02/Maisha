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

    // Temperature plausibility bounds (physical sanity, not clinical thresholds)
    private const TEMP_CELSIUS_MIN = 30.0;
    private const TEMP_CELSIUS_MAX = 43.0;
    private const TEMP_FAHRENHEIT_MIN = 86.0;
    private const TEMP_FAHRENHEIT_MAX = 109.4;

    // Weight — absolute bounds only used as a last-resort sanity check when
    // there's no prior reading to compare against (catches e.g. a BMI or
    // body-fat % misread as weight, not real outlier detection).
    private const WEIGHT_KG_MIN = 20.0;
    private const WEIGHT_KG_MAX = 300.0;
    private const WEIGHT_LBS_MIN = 44.0;
    private const WEIGHT_LBS_MAX = 660.0;

    // Weight — max plausible swing between consecutive readings.
    private const WEIGHT_CHANGE_THRESHOLD_PERCENT = 0.15;

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

    public static function isPlausibleTemperature(?float $value, ?string $unit): bool
    {
        if ($value === null || !in_array($unit, ['celsius', 'fahrenheit'], true)) {
            return false;
        }

        if ($unit === 'celsius') {
            return $value >= self::TEMP_CELSIUS_MIN && $value <= self::TEMP_CELSIUS_MAX;
        }

        return $value >= self::TEMP_FAHRENHEIT_MIN && $value <= self::TEMP_FAHRENHEIT_MAX;
    }

    /**
     * Weight has no sane absolute range across all adult body types, so this
     * is primarily a %-change-from-previous check. Callers must fetch the
     * user's last weight reading before calling this.
     *
     * $previousValue and $previousUnit should be null on a user's first-ever
     * weight reading — in that case we fall back to the absolute bounds only,
     * and the caller should treat a true result as "no baseline yet" rather
     * than a confirmed-normal reading.
     */
    public static function isPlausibleWeight(
        ?float $value,
        ?string $unit,
        ?float $previousValue = null,
        ?string $previousUnit = null
    ): bool {
        if ($value === null || !in_array($unit, ['kg', 'lbs'], true)) {
            return false;
        }

        $withinAbsoluteBounds = $unit === 'kg'
            ? ($value >= self::WEIGHT_KG_MIN && $value <= self::WEIGHT_KG_MAX)
            : ($value >= self::WEIGHT_LBS_MIN && $value <= self::WEIGHT_LBS_MAX);

        if (!$withinAbsoluteBounds) {
            return false;
        }

        if ($previousValue === null || $previousUnit === null) {
            return true; // no baseline to compare against
        }

        $previousInCurrentUnit = self::convertWeight($previousValue, $previousUnit, $unit);

        if ($previousInCurrentUnit <= 0) {
            return true;
        }

        $percentChange = abs($value - $previousInCurrentUnit) / $previousInCurrentUnit;

        return $percentChange <= self::WEIGHT_CHANGE_THRESHOLD_PERCENT;
    }

    private static function convertWeight(float $value, string $fromUnit, string $toUnit): float
    {
        if ($fromUnit === $toUnit) {
            return $value;
        }

        return $fromUnit === 'kg'
            ? $value * 2.20462 // kg -> lbs
            : $value / 2.20462; // lbs -> kg
    }
}