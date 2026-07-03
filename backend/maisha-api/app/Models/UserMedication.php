<?php
// app/Models/UserMedication.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class UserMedication extends Model
{
    protected $table = 'user_medications';

    protected $fillable = [
        'user_id',
        'name',
        'dosage',
        'frequency',
        'food_condition',
        'meal_periods',
        'duration_type',
        'duration_days',
        'expires_on',
        'condition_source',
        'is_active',
        'notes',
        // Legacy
        'times',
        'requires_food',
        'meal_slot_anchor',
        // Day 5 – new
        'estimated_cost_kes',
        'added_during_onboarding',
    ];

    protected $casts = [
        'times'        => 'array',
        'meal_periods' => 'array',
        'requires_food'=> 'boolean',
        'is_active'    => 'boolean',
        'expires_on'   => 'date',
        // Day 5 – new
        'estimated_cost_kes'      => 'decimal:2',
        'added_during_onboarding' => 'boolean',
    ];

    // ── Scopes ─────────────────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where(function ($q) {
                         $q->whereNull('expires_on')
                           ->orWhere('expires_on', '>=', today());
                     });
    }

    // ── Accessor ──────────────────────────────────────────────────────
    public function getRequiresFoodAttribute($value): bool
    {
        if (!is_null($this->food_condition) && $this->food_condition !== 'none') {
            return in_array($this->food_condition, ['with_food', 'before_food', 'after_food']);
        }
        return (bool) $value;
    }

    // ── Boot ──────────────────────────────────────────────────────────
    public static function boot()
    {
        parent::boot();

        static::creating(function ($med) {
            if ($med->duration_type === 'days' && $med->duration_days) {
                $med->expires_on = Carbon::today()->addDays($med->duration_days);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}