<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationExtractionReview extends Model
{
    protected $fillable = ['user_id', 'media_sid', 'extracted_data', 'confidence', 'status'];

    protected $casts = [
        'extracted_data' => 'array',
    ];

    public function user() { return $this->belongsTo(User::class); }
}