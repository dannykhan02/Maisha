<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActivityProfile extends Model
{
    protected $table = 'user_activity_profiles';
    protected $fillable = ['user_id', 'activity_level', 'exercise_frequency', 'sleep_schedule'];

    public function user() { return $this->belongsTo(User::class); }
}