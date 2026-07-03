<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSession extends Model
{
    protected $fillable = [
        'user_id',
        'wa_number',
        'state',
        'context',
        'last_message_at',
        'linked_at',      // added to allow mass assignment
    ];

    protected $casts = [
        'context'        => 'array',
        'last_message_at' => 'datetime',
        'linked_at'      => 'datetime',   // added for proper datetime casting
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}