<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $ingredients = Ingredient::where('available', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data'  => $ingredients,
            'count' => $ingredients->count(),
        ]);
    }
}