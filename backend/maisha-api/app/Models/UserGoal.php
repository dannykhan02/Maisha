<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

// UPDATE 2026-07-03: this table is NOT dead. GoalController writes to
// it and it is the only source for target_weight_kg/timeline_weeks.
// GoalController::update also syncs a flattened list to User.primary_goals
// on every write, so both stay consistent. No frontend caller currently
// exists (confirmed via full grep of frontend/src, 2026-07-03) — this
// appears to be backend groundwork for the not-yet-built
// health-profile-edit screen (Item #7). Revisit once that screen ships.
class UserGoal extends Model
{
    protected $table = 'user_goals';
    protected $fillable = ['user_id', 'primary_goal', 'secondary_goals', 'target_weight_kg', 'timeline_weeks'];
    protected $casts = ['secondary_goals' => 'array'];
    public function user() { return $this->belongsTo(User::class); }
}
