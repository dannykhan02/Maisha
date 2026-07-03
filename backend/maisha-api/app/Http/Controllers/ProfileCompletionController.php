<?php

namespace App\Http\Controllers;

use App\Models\HealthProfile;
use App\Models\User;
use App\Models\UserMealPattern;
use App\Models\UserPantry;
use App\Models\UserActivityProfile;
use App\Models\UserMedication;
use Illuminate\Http\Request;

class ProfileCompletionController extends Controller
{
    public function completion(Request $request)
    {
        $userId = $request->user()->id;

        $completion = [
            'health'     => $this->healthComplete($userId),
            'diet'       => $this->dietComplete($userId),
            'medication' => $this->medicationComplete($userId),
            'pantry'     => $this->pantryComplete($userId),
            'activity'   => $this->activityComplete($userId),
            'goals'      => $this->goalsComplete($userId),
        ];

        $overall    = array_sum($completion) / count($completion);
        $incomplete = array_keys(array_filter($completion, fn($v) => $v < 100));

        return response()->json([
            'completion'     => $completion,
            'overall'        => round($overall, 2),
            'incomplete'     => $incomplete,
            'setup_required' => [
                'diet'       => $completion['diet'] < 100,
                'medication' => false,
            ],
        ]);
    }

    private function healthComplete(int $userId): int
    {
        $profile = HealthProfile::where('user_id', $userId)->first();
        if (!$profile) return 0;

        // Explicit confirmation through the wizard = fully done
        if ($profile->health_confirmed) return 100;

        // Seeded/legacy users with raw data but no wizard pass = partial credit
        $hasData = !empty($profile->conditions) || !empty($profile->allergies);
        return $hasData ? 50 : 0;
    }

    private function dietComplete(int $userId): int
    {
        $pattern = UserMealPattern::where('user_id', $userId)->first();
        if (!$pattern) return 0;

        $score = 0;

        // meals_per_day: any non-null value (including 0) counts as set
        if (!is_null($pattern->meals_per_day)) $score += 25;

        // dietary_identity: null = never set, [] = "no restrictions" (valid complete answer)
        // Both truthy arrays and empty arrays count — only null is incomplete
        if (!is_null($pattern->dietary_identity)) $score += 25;

        // cooking_source: any non-empty string
        if (!empty($pattern->cooking_source)) $score += 25;

        // budget_split: any non-empty string — "app_decides" is a valid complete answer
        if (!empty($pattern->budget_split)) $score += 25;

        return $score;
    }

    private function medicationComplete(int $userId): int
    {
        // Zero medications is a valid complete state
        return 100;
    }

    private function pantryComplete(int $userId): int
    {
        $count = UserPantry::where('user_id', $userId)->count();
        return $count >= 3 ? 100 : min(100, $count * 33);
    }

    private function activityComplete(int $userId): int
    {
        $profile = UserActivityProfile::where('user_id', $userId)->first();
        return $profile && $profile->activity_level ? 100 : 0;
    }

    private function goalsComplete(int $userId): int
    {
        $user = User::find($userId);
        return $user && !empty($user->primary_goals) ? 100 : 0;
    }
}