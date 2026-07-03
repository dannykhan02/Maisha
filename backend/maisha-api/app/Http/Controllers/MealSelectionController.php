<?php

namespace App\Http\Controllers;

use App\Models\SuggestionHistory;
use App\Models\MealLog;
use App\Models\UserPantry;
use Illuminate\Http\Request;

class MealSelectionController extends Controller
{
    /**
     * POST /api/meal-selection
     *
     * Records which category options were shown and which the user picked.
     * Writes was_selected=true for chosen items, was_ignored=true for the rest.
     * Also logs the selected meal to meal_logs.
     * Decrements pantry stock for selected ingredients.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'slot'                      => 'required|string',
            'shown_ingredient_ids'      => 'required|array',
            'shown_ingredient_ids.*'    => 'integer|exists:ingredients,id',
            'selected_ingredient_ids'   => 'required|array',
            'selected_ingredient_ids.*' => 'integer|exists:ingredients,id',
            'total_kcal'                => 'nullable|numeric',
            'total_cost_kes'            => 'nullable|numeric',
        ]);

        $user     = $request->user();
        $today    = today();
        $selected = $data['selected_ingredient_ids'];

        // Record suggestion history
        foreach ($data['shown_ingredient_ids'] as $ingredientId) {
            $wasSelected = in_array($ingredientId, $selected);

            SuggestionHistory::create([
                'user_id'       => $user->id,
                'date'          => $today,
                'slot'          => $data['slot'],
                'ingredient_id' => $ingredientId,
                'was_selected'  => $wasSelected,
                'was_ignored'   => !$wasSelected,
            ]);
        }

        // Log meal
        MealLog::create([
            'user_id'        => $user->id,
            'date'           => $today,
            'slot'           => $data['slot'],
            'ingredient_ids' => $selected,
            'total_kcal'     => $data['total_kcal'] ?? 0,
            'total_cost_kes' => $data['total_cost_kes'] ?? 0,
            'logged_via'     => 'app',
        ]);

        // Decrement pantry stock
        foreach ($selected as $ingredientId) {

            $pantryItem = UserPantry::where('user_id', $user->id)
                ->where('ingredient_id', $ingredientId)
                ->first();

            if (
                $pantryItem &&
                !$pantryItem->is_depleted &&
                $pantryItem->quantity > 0
            ) {
                $pantryItem->quantity -= 1;
                $pantryItem->last_decremented_at = now();

                if ($pantryItem->quantity <= 0) {
                    $pantryItem->is_depleted = true;
                }

                $pantryItem->save();
            }
        }

        return response()->json([
            'saved'             => true,
            'recorded_for_date' => $today->toDateString(),
            'selected_count'    => count($selected),
            'ignored_count'     => count($data['shown_ingredient_ids']) - count($selected),
        ]);
    }
}