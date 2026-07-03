<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetLog extends Model
{
    protected $fillable = [
        'user_id', 'date', 'daily_limit_kes', 'spent_kes', 'expense_count',
    ];

    protected $casts = [
        'date'            => 'date',
        'daily_limit_kes' => 'decimal:2',
        'spent_kes'       => 'decimal:2',
    ];

    public function getSavedKesAttribute(): float
    {
        return max(0, $this->daily_limit_kes - $this->spent_kes);
    }

    public function getRemainingKesAttribute(): float
    {
        return max(0, $this->daily_limit_kes - $this->spent_kes);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function expenses()
    {
        return $this->hasMany(ExpenseLog::class);
    }
}