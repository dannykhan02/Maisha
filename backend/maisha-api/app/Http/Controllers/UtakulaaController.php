<?php

namespace App\Http\Controllers;

use App\Services\UtakulaaService;
use Illuminate\Http\Request;

class UtakulaaController extends Controller
{
    public function store(Request $request, UtakulaaService $service)
    {
        $user   = $request->user();
        // Accept both 'budget_kes' (frontend) and 'budget' (legacy) keys
        $budget = (float) ($request->input('budget_kes')
            ?? $request->input('budget')
            ?? $user->daily_budget_kes
            ?? 150);

        try {
            $result = $service->getMealPlan($user, $budget);
            $service->saveSuggestion($user, $result, 'web');
            return response()->json($result);

        } catch (\RuntimeException $e) {
            return response()->json([
                'error'    => $e->getMessage(),
                'fallback' => true,
            ], 503);
        }
    }

    public function index(Request $request)
    {
        $suggestions = $request->user()
            ->mealSuggestions()
            ->orderByDesc('suggested_at')
            ->limit(20)
            ->get();

        return response()->json(['data' => $suggestions]);
    }
}