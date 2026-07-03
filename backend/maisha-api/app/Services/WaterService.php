<?php

namespace App\Services;

use App\Models\User;

class WaterService
{
    public function calculateTarget(User $user): int
    {
        $weight = $user->weight_kg ?? 65.0;
        $multiplier = match ($user->activity_level ?? 'moderate') {
            'sedentary' => 1.0,
            'moderate'  => 1.2,
            'active'    => 1.4,
            default     => 1.2,
        };
        $target = $weight * 35 * $multiplier;
        return (int) min(max($target, 1500), 4000);
    }

    public function calculationNotes(User $user): array
    {
        $weight = $user->weight_kg ?? 65.0;
        $activity = $user->activity_level ?? 'moderate';
        $multiplier = match ($activity) {
            'sedentary' => 1.0, 'moderate' => 1.2, 'active' => 1.4, default => 1.2,
        };
        return [
            'weight_kg'    => $weight,
            'activity'     => $activity,
            'multiplier'   => $multiplier,
            'formula'      => "{$weight} × 35 × {$multiplier}",
            'raw_result'   => round($weight * 35 * $multiplier),
            'using_default_weight' => $user->weight_kg === null,
        ];
    }
}


