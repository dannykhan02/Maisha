<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuggestionHistory extends Model
{
    protected $table = 'suggestion_history';

    protected $fillable = [
        'user_id', 'date', 'slot', 'ingredient_id',
        'was_selected', 'was_ignored',
    ];

    protected $casts = [
        'date'         => 'date',
        'was_selected' => 'boolean',
        'was_ignored'  => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}