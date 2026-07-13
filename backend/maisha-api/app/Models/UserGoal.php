<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// UPDATE 2026-07-04 (Item #7 closure): frontend caller now exists —
// Settings > Goals (src/pages/Settings.tsx, 'goals' section) calls
// GoalController via src/lib/api.ts's goalApi. GoalController::update
// syncs a flattened list to User.primary_goals on every write, so both
// stay consistent — see GoalController for the enum of valid goal values,
// which must match frontend/src/lib/goalOptions.ts.
class UserGoal extends Model
{
    protected $table = 'user_goals';
    protected $fillable = ['user_id', 'primary_goal', 'secondary_goals', 'target_weight_kg', 'timeline_weeks'];
    protected $casts = [
        'secondary_goals'  => 'array',
        'target_weight_kg' => 'decimal:1',
        'timeline_weeks'   => 'integer',
    ];
    public function user() { return $this->belongsTo(User::class); }
}