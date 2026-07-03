<?php

namespace App\Http\Controllers;

use App\Models\BudgetLog;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    /**
     * Get today's budget summary for the authenticated user.
     */
    public function today(Request $request)
    {
        $user = $request->user();

        $log = BudgetLog::firstOrCreate(
            ['user_id' => $user->id, 'date' => today()],
            ['daily_limit_kes' => $user->daily_budget_kes, 'spent_kes' => 0]
        );

        return response()->json([
            'date'            => $log->date->toDateString(),
            'daily_limit'     => (float) $log->daily_limit_kes,
            'spent'           => (float) $log->spent_kes,
            'saved'           => max(0, $log->daily_limit_kes - $log->spent_kes),
            'remaining'       => max(0, $log->daily_limit_kes - $log->spent_kes),
            'percentage_used' => $log->daily_limit_kes > 0
                ? round(($log->spent_kes / $log->daily_limit_kes) * 100)
                : 0,
        ]);
    }

    /**
     * Get the last 7 days of budget logs (weekly summary).
     */
    public function weekly(Request $request)
    {
        $logs = BudgetLog::where('user_id', $request->user()->id)
            ->whereBetween('date', [now()->subDays(6)->toDateString(), today()->toDateString()])
            ->orderBy('date')
            ->get()
            ->map(fn($log) => [
                'date'        => $log->date->toDateString(),
                'daily_limit' => (float) $log->daily_limit_kes,
                'spent'       => (float) $log->spent_kes,
                'saved'       => max(0, $log->daily_limit_kes - $log->spent_kes),
            ]);

        return response()->json([
            'data'          => $logs,
            'total_saved'   => $logs->sum('saved'),
            'total_spent'   => $logs->sum('spent'),
            'days_returned' => $logs->count(),
        ]);
    }
}