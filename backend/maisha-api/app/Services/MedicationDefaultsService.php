<?php
// app/Services/MedicationDefaultsService.php

namespace App\Services;

class MedicationDefaultsService
{
    public function inferMealPeriods(string $frequency): array
    {
        return match($frequency) {
            'once_daily'         => ['morning'],
            'twice_daily'        => ['morning', 'evening'],
            'three_times_daily'  => ['morning', 'midday', 'evening'],
            'as_needed'          => ['any'],
            default              => ['morning'],
        };
    }

    public function inferTimes(string $frequency): array
    {
        return match($frequency) {
            'once_daily'         => ['08:00'],
            'twice_daily'        => ['08:00', '20:00'],
            'three_times_daily'  => ['08:00', '13:00', '20:00'],
            'as_needed'          => [],
            default              => ['08:00'],
        };
    }

    public function requiresFood(string $foodCondition): bool
    {
        return in_array($foodCondition, ['with_food', 'before_food', 'after_food']);
    }
}