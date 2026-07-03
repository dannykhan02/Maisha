<?php

namespace App\Http\Controllers;

use App\Models\UserPantry;
use Illuminate\Http\Request;

class PantryController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'ingredient_id' => 'required|exists:ingredients,id',
            'tier'          => 'required|integer|in:1,2,3',
            'quantity'      => 'nullable|numeric',
            'unit'          => 'nullable|string|max:20',
            'is_depleted'   => 'nullable|boolean',
        ]);

        // Find existing pantry item
        $existing = UserPantry::where('user_id', $request->user()->id)
            ->where('ingredient_id', $data['ingredient_id'])
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Auto-Restock Logic
        |--------------------------------------------------------------------------
        | If the user manually increases stock:
        | 1. Clear the decrement timestamp
        | 2. Mark the ingredient as not depleted
        */
        if (
            $existing &&
            isset($data['quantity']) &&
            $existing->quantity !== null &&
            $data['quantity'] > $existing->quantity
        ) {
            $data['last_decremented_at'] = null;
            $data['is_depleted'] = false;
        }

        $pantry = UserPantry::updateOrCreate(
            [
                'user_id'       => $request->user()->id,
                'ingredient_id' => $data['ingredient_id'],
            ],
            $data
        );

        return response()->json([
            'saved' => true,
            'item'  => $pantry,
        ]);
    }

    public function list(Request $request)
    {
        $items = UserPantry::with('ingredient')
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json($items);
    }

    public function destroy(Request $request, $ingredientId)
    {
        UserPantry::where('user_id', $request->user()->id)
            ->where('ingredient_id', $ingredientId)
            ->delete();

        return response()->json([
            'deleted' => true,
        ]);
    }
}