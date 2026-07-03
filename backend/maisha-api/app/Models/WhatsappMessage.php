<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    protected $fillable = [
        'payload',
        'received_at',
        'processed_at',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}