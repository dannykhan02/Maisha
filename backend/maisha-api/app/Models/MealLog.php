<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealLog extends Model
{
    protected $fillable = [
        'user_id', 'date', 'slot', 'ingredient_ids',
        'total_kcal', 'total_cost_kes', 'logged_via',
    ];

    protected $casts = [
        'date'           => 'date',
        'ingredient_ids' => 'array',
        'total_kcal'     => 'decimal:2',
        'total_cost_kes' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}