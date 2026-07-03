<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaterDailySummary extends Model
{
    protected $fillable = [
        'user_id', 'date', 'target_ml', 'consumed_ml',
        'target_met', 'target_calculation_notes', 'log_count',
    ];

    protected $casts = [
        'date'                       => 'date',
        'target_met'                 => 'boolean',
        'target_calculation_notes'   => 'array',
        'target_ml'                  => 'integer',
        'consumed_ml'                => 'integer',
    ];

    public function getProgressPercentAttribute(): int
    {
        if ($this->target_ml === 0) return 0;
        return (int) min(100, round(($this->consumed_ml / $this->target_ml) * 100));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}