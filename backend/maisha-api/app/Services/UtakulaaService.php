<?php
// app/Services/UtakulaaService.php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\MealSuggestion;
use App\Models\BudgetLog;
use App\Models\User;
use App\Models\UserPantry;
use App\Models\UserMedication;
use App\Models\UserMealPattern;
use App\Models\UserActivityProfile;
use App\Models\SuggestionHistory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UtakulaaService
{
    public function getMealPlan(User $user, float $budget): array
    {
        $profile     = $user->healthProfile;
        $ingredients = $this->buildIngredientList();
        $todaySpent  = $this->getTodaySpent($user);
        $mealPattern = UserMealPattern::where('user_id', $user->id)->first();
        $activity    = UserActivityProfile::where('user_id', $user->id)->first();

        $pantry = UserPantry::with('ingredient')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn($item) => [
                'ingredient_id' => $item->ingredient_id,
                'tier'          => $item->tier,
                'quantity'      => $item->quantity,
                'unit'          => $item->unit,
                'is_depleted'   => $item->is_depleted,
            ])->toArray();

        // ── Medications: send full enriched data, active only ──────────────
        $medications = UserMedication::where('user_id', $user->id)
            ->active()
            ->get()
            ->map(fn($med) => [
                // Legacy fields — alert controller + old Flask reads
                'name'             => $med->name,
                'times'            => $med->times ?? [],
                'requires_food'    => $med->requires_food,
                'meal_slot_anchor' => $med->meal_slot_anchor,
                // New fields — _medication_notes() in Flask uses these
                'dosage'           => $med->dosage,
                'frequency'        => $med->frequency,
                'food_condition'   => $med->food_condition,
                'meal_periods'     => $med->meal_periods ?? [],
                'condition_source' => $med->condition_source,
                // Day 9 — collision detection needs this
                'estimated_cost_kes' => $med->estimated_cost_kes
                    ? (float) $med->estimated_cost_kes
                    : null,
            ])->toArray();

        // ── Active meal slots derived from meals_per_day ───────────────────
        // Flask needs active_slots — derive from meals_per_day + pattern type
        $activeSlots = $this->deriveActiveSlots($mealPattern);

        // ── Budget split across slots ──────────────────────────────────────
        // If user set a preference, apply it. Otherwise Flask distributes.
        $budgetSplitWeights = $mealPattern?->getBudgetWeights();

        $history = $this->getSuggestionHistory($user->id);

        $payload = [
            // ── Identity ──────────────────────────────────────────────────
            'user_id'              => $user->id,

            // ── Budget ────────────────────────────────────────────────────
            'budget_remaining_kes' => max(0, $budget - $todaySpent),
            'today_spent_kes'      => $todaySpent,
            'budget_split_weights' => $budgetSplitWeights, // null = Flask decides

            // ── Goals — ALL of them, not just first ───────────────────────
            'primary_goals'        => $user->primary_goals ?? [],
            'fitness_goal'         => $profile?->fitness_goal ?? 'maintain',

            // ── Health ────────────────────────────────────────────────────
            'health_conditions'    => $profile?->conditions    ?? [],
            'allergies'            => $profile?->allergies     ?? [],
            'sensitivities'        => $profile?->sensitivities ?? [],

            // ── Anthropometrics ───────────────────────────────────────────
            'weight_kg'            => $user->weight_kg,
            'height_cm'           => $user->height_cm,
            'age'                  => $user->age,
            'gender'               => $user->gender ?? 'female',

            // ── Activity ──────────────────────────────────────────────────
            'activity_level'       => $activity?->activity_level ?? 'moderate',

            // ── Meal pattern — full diet profile ──────────────────────────
            'meals_per_day'        => $mealPattern?->meals_per_day    ?? 3,
            'active_slots'         => $activeSlots,
            'dietary_identity'     => $mealPattern?->dietary_identity ?? [],
            'food_dislikes'        => $mealPattern?->food_dislikes    ?? [],
            'cooking_source'       => $mealPattern?->cooking_source   ?? 'both',
            'meal_prep_time'       => $mealPattern?->meal_prep_time   ?? 'moderate',
            'protein_preference'   => $mealPattern?->protein_preference ?? 'any',
            'staple_preference'    => $mealPattern?->staple_preference  ?? [],

            // ── Medications — enriched ────────────────────────────────────
            'medications'          => $medications,

            // ── Ingredients + pantry ──────────────────────────────────────
            'ingredients'          => $ingredients,
            'pantry'               => $pantry,

            // ── Variety history ───────────────────────────────────────────
            'suggestion_history'   => $history,
        ];

        $response = Http::timeout(20)
            ->withHeaders([
                'X-Maisha-Internal-Token' => config('services.flask.secret'),
                'Content-Type'            => 'application/json',
            ])
            ->post(config('services.flask.url') . '/api/utakulaa', $payload);

        if ($response->failed()) {
            Log::error('Flask /api/utakulaa failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('AI engine unavailable — please try again');
        }

        return $response->json();
    }

    public function saveSuggestion(User $user, array $result, string $channel = 'web'): MealSuggestion
    {
        $top = $result['top_meal'] ?? [];
        return MealSuggestion::create([
            'user_id'          => $user->id,
            'ingredient_ids'   => $top['ingredient_ids'] ?? [],
            'meal_name'        => $top['name']           ?? '',
            'total_cost_kes'   => $top['total_cost_kes'] ?? 0,
            'total_calories'   => $top['total_calories'] ?? 0,
            'total_protein_g'  => $top['protein_g']      ?? 0,
            'algorithm_score'  => $top['score']          ?? 0,
            'explanation'      => $result['explanation'] ?? '',
            'health_notes'     => $result['health_notes'] ?? [],
            'savings_kes'      => $result['savings_kes']  ?? 0,
            'ai_provider_used' => $result['ai_provider_used'] ?? 'claude',
            'channel'          => $channel,
            'suggested_at'     => now(),
        ]);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Derive active meal slots from the user's meal pattern.
     *
     * Flask's slot_normaliser accepts these canonical slot names.
     * We map meals_per_day to the most logical slot set.
     * User's meal_pattern field can override if set.
     */
    private function deriveActiveSlots(?UserMealPattern $pattern): array
    {
        if (!$pattern) {
            return ['breakfast', 'lunch', 'dinner'];
        }

        // If meal_pattern field is set and maps to slots, use it
        $patternSlotMap = [
            'intermittent_fasting' => ['lunch', 'dinner'],
            'two_meals'            => ['lunch', 'dinner'],
            'grazing'              => ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner'],
        ];

        if ($pattern->meal_pattern && isset($patternSlotMap[$pattern->meal_pattern])) {
            return $patternSlotMap[$pattern->meal_pattern];
        }

        // Derive from meals_per_day count
        return match((int) ($pattern->meals_per_day ?? 3)) {
            1 => ['lunch'],
            2 => ['lunch', 'dinner'],
            3 => ['breakfast', 'lunch', 'dinner'],
            4 => ['breakfast', 'lunch', 'snack_pm', 'dinner'],
            5 => ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner'],
            6 => ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner', 'supper'],
            default => ['breakfast', 'lunch', 'dinner'],
        };
    }

    private function buildIngredientList(): array
    {
        return Ingredient::where('available', true)
            ->get()
            ->map(fn($i) => [
                'id'              => $i->id,
                'name'            => $i->name,
                'name_sw'         => $i->name_sw,
                'category'        => $i->category,
                'price_kes'       => (float) $i->price_kes,
                'calories'        => (float) $i->calories,
                'protein_g'       => (float) $i->protein_g,
                'carbs_g'         => (float) $i->carbs_g,
                'fat_g'           => (float) $i->fat_g,
                'fibre_g'         => (float) $i->fibre_g,
                'condition_flags' => $i->condition_flags ?? [],
                'allergen_flags'  => $i->allergen_flags  ?? [],
                'available'       => (bool) $i->available,
                'in_season'       => (bool) $i->in_season,
            ])
            ->toArray();
    }

    private function getTodaySpent(User $user): float
    {
        return (float) BudgetLog::where('user_id', $user->id)
            ->where('date', today())
            ->value('spent_kes') ?? 0;
    }

    private function getSuggestionHistory(int $userId): array
    {
        return SuggestionHistory::where('user_id', $userId)
            ->where('date', '>=', now()->subDays(14))
            ->where('was_selected', true)
            ->get(['ingredient_id', 'date'])
            ->groupBy('ingredient_id')
            ->map(function ($items) {
                $lastUsed = $items->max('date');
                return [
                    'last_used_at' => $lastUsed ? $lastUsed->toDateString() : null,
                    'usage_count'  => $items->count(),
                ];
            })
            ->filter(fn($item) => !is_null($item['last_used_at']))
            ->toArray();
    }
}