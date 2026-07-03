<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserHabit extends Model
{
    protected $fillable = [
        'user_id', 'habit_id', 'display_order', 'current_streak',
        'longest_streak', 'status', 'started_at', 'last_completed_at',
    ];

    protected $casts = [
        'started_at'        => 'date',
        'last_completed_at' => 'date',
        'current_streak'    => 'integer',
        'longest_streak'    => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function habit()
    {
        return $this->belongsTo(Habit::class);
    }

    public function logs()
    {
        return $this->hasMany(HabitLog::class);
    }
}