<?php
// app/Http/Controllers/MealPatternController.php

namespace App\Http\Controllers;

use App\Models\UserMealPattern;
use Illuminate\Http\Request;

class MealPatternController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'meals_per_day'        => 'nullable|integer|min:1|max:6',
            'preferred_meal_times' => 'nullable|array',
            'meal_pattern'         => 'nullable|string',
            'cuisine_preference'   => 'nullable|string',
            'is_active'            => 'nullable|boolean',
            'dietary_identity'     => 'nullable|array',
            'dietary_identity.*'   => 'string|in:none,vegetarian,vegan,halal,no_beef,no_pork,gluten_free,dairy_free',
            'food_dislikes'        => 'nullable|array',
            'food_dislikes.*'      => 'string|max:50',
            'budget_split'         => 'nullable|in:equal,bigger_breakfast,bigger_lunch,bigger_dinner,app_decides',
            'cooking_source'       => 'nullable|in:home,food_stalls,both',
            'meal_prep_time'       => 'nullable|in:quick,moderate,no_limit',
            'protein_preference'   => 'nullable|in:any,chicken,fish,beef,eggs_dairy,plant_only',
            'staple_preference'    => 'nullable|array',
            'staple_preference.*'  => 'string|in:ugali,rice,chapati,githeri,matoke,bread,sweet_potato,arrow_root,pasta,sorghum',
            'allergies_confirmed'  => 'nullable|boolean',
        ]);

        $pattern = UserMealPattern::updateOrCreate(
            ['user_id' => $request->user()->id],
            $data
        );

        return response()->json(['saved' => true, 'pattern' => $pattern]);
    }

    public function show(Request $request)
    {
        $pattern = UserMealPattern::where('user_id', $request->user()->id)->first();
        return response()->json($pattern ?? []);
    }
}