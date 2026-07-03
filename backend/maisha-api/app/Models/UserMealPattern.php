<?php
// app/Models/UserMealPattern.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMealPattern extends Model
{
    protected $table = 'user_meal_patterns';

    protected $fillable = [
        'user_id',
        'meals_per_day',
        'preferred_meal_times',
        'meal_pattern',
        'cuisine_preference',
        'is_active',
        // New
        'dietary_identity',
        'food_dislikes',
        'budget_split',
        'cooking_source',
        'meal_prep_time',
        'protein_preference',
        'staple_preference',
        'allergies_confirmed',
    ];

    protected $casts = [
        'preferred_meal_times' => 'array',
        'dietary_identity'     => 'array',
        'food_dislikes'        => 'array',
        'staple_preference'    => 'array',
        'is_active'            => 'boolean',
        'allergies_confirmed'  => 'boolean',
    ];

    // ── Budget weights helper ────────────────────────────────────────
    public function getBudgetWeights(): array
    {
        $meals = $this->meals_per_day ?? 3;

        return match($this->budget_split) {
            'bigger_breakfast' => $this->weights([0.40, 0.35, 0.25], $meals),
            'bigger_lunch'     => $this->weights([0.25, 0.50, 0.25], $meals),
            'bigger_dinner'    => $this->weights([0.20, 0.30, 0.50], $meals),
            'equal'            => $this->weights(array_fill(0, $meals, 1 / $meals), $meals),
            default            => [], // app_decides → Flask handles
        };
    }

    private function weights(array $splits, int $meals): array
    {
        $splits = array_slice($splits, 0, $meals);
        while (count($splits) < $meals) {
            $splits[] = 1 / $meals;
        }
        return $splits;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}