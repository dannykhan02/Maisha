<?php

namespace App\Http\Controllers;

use App\Models\UserActivityProfile;
use Illuminate\Http\Request;

class ActivityProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'activity_level'     => 'nullable|string|in:sedentary,light,moderate,active,very_active',
            'exercise_frequency' => 'nullable|string',
            'sleep_schedule'     => 'nullable|string',
        ]);
        $profile = UserActivityProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $data
        );
        return response()->json(['saved' => true, 'profile' => $profile]);
    }

    public function show(Request $request)
    {
        $profile = UserActivityProfile::where('user_id', $request->user()->id)->first();
        return response()->json($profile ?? []);
    }
}