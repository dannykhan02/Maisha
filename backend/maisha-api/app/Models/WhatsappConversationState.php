<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappConversationState extends Model
{
    protected $fillable = ['user_id', 'flow', 'step', 'context', 'expires_at'];

    protected $casts = [
        'context'    => 'array',
        'expires_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}