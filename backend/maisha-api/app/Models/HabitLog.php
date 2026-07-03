<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HabitLog extends Model
{
    protected $fillable = [
        'user_id', 'user_habit_id', 'date', 'completed_at', 'channel',
    ];

    protected $casts = [
        'date'         => 'date',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function userHabit()
    {
        return $this->belongsTo(UserHabit::class);
    }
}