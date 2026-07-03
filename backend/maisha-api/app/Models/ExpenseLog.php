<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseLog extends Model
{
    protected $fillable = [
        'user_id', 'budget_log_id', 'amount_kes', 'description',
        'meal_suggestion_id', 'logged_via',
    ];

    protected $casts = [
        'amount_kes' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function budgetLog()
    {
        return $this->belongsTo(BudgetLog::class);
    }

    public function mealSuggestion()
    {
        return $this->belongsTo(MealSuggestion::class);
    }
}