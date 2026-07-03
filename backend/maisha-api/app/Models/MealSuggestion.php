<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealSuggestion extends Model
{
    protected $fillable = [
        'user_id', 'ingredient_ids', 'meal_name', 'total_cost_kes',
        'total_calories', 'total_protein_g', 'algorithm_score',
        'explanation', 'health_notes', 'ai_provider_used',
        'savings_kes', 'channel', 'accepted', 'suggested_at',
    ];

    protected $casts = [
        'ingredient_ids' => 'array',
        'health_notes'   => 'array',
        'accepted'       => 'boolean',
        'total_cost_kes' => 'decimal:2',
        'savings_kes'    => 'decimal:2',
        'suggested_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}