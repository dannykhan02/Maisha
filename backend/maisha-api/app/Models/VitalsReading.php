<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VitalsReading extends Model
{
    protected $fillable = [
        'user_id', 'type', 'systolic', 'diastolic', 'pulse',
        'sugar_value', 'sugar_unit',
        'temperature_value', 'temperature_unit',
        'weight_value', 'weight_unit',
        'spo2_value',
        'is_outlier', 'recorded_via', 'media_sid', 'recorded_at',
    ];

    protected $casts = [
        'is_outlier'  => 'boolean',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (VitalsReading $reading) {
            // 'oximeter' added alongside the spo2_value column (see
            // migration 2026_07_17_000000_add_spo2_value_to_vitals_readings_table).
            // `pulse` is intentionally NOT type-gated here — it's shared
            // between 'bp' and 'oximeter' readings on purpose, since both
            // represent the same underlying heart-rate measurement.
            $valid = ['bp', 'sugar', 'temperature', 'weight', 'oximeter'];
            if (!in_array($reading->type, $valid, true)) {
                throw new \InvalidArgumentException("Invalid vitals_readings type: {$reading->type}");
            }
        });
    }

    public function user() { return $this->belongsTo(User::class); }
}