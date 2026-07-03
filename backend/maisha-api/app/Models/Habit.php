<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Habit extends Model
{
    protected $fillable = [
        'name',
        'name_sw',
        'category',
        'habit_direction',
        'limit_target',
        'limit_unit',
        'difficulty',
        'trigger_time',
        'duration_estimate',
        'frequency',
        'is_keystone',
        'recommended_for_conditions',
        'recommended_for_goals',
        'is_system',
    ];

    protected $casts = [
        'recommended_for_conditions' => 'array',
        'recommended_for_goals'      => 'array',
        'is_keystone'                => 'boolean',
        'is_system'                  => 'boolean',
        'limit_target'               => 'integer',
    ];
}