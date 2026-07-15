<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VitalsReading extends Model
{
    protected $fillable = [
        'user_id', 'type', 'systolic', 'diastolic',
        'sugar_value', 'sugar_unit', 'is_outlier',
        'recorded_via', 'recorded_at',
    ];

    protected $casts = [
        'is_outlier'  => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}