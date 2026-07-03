<?php

namespace App\Http\Controllers;

use App\Models\{BudgetLog, ExpenseLog};
use Illuminate\Http\Request;

class ExpenseLogController extends Controller
{
    /**
     * Log a new expense for the authenticated user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'amount_kes'  => 'required|numeric|min:1|max:10000',
            'description' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        // Get or create today's budget log
        $budgetLog = BudgetLog::firstOrCreate(
            ['user_id' => $user->id, 'date' => today()],
            ['daily_limit_kes' => $user->daily_budget_kes, 'spent_kes' => 0]
        );

        // Create the expense record
        ExpenseLog::create([
            'user_id'       => $user->id,
            'budget_log_id' => $budgetLog->id,
            'amount_kes'    => $validated['amount_kes'],
            'description'   => $validated['description'] ?? null,
            'logged_via'    => 'web',
        ]);

        // Update the budget log counters
        $budgetLog->increment('spent_kes', $validated['amount_kes']);
        $budgetLog->increment('expense_count');
        $budgetLog->refresh();

        return response()->json([
            'logged'      => (float) $validated['amount_kes'],
            'spent_today' => (float) $budgetLog->spent_kes,
            'remaining'   => max(0, $budgetLog->daily_limit_kes - $budgetLog->spent_kes),
            'daily_limit' => (float) $budgetLog->daily_limit_kes,
        ]);
    }
}