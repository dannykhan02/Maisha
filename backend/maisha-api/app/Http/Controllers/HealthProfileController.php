<?php

namespace App\Http\Controllers;

use App\Models\HealthProfile;   // ← changed from UserHealthProfile
use Illuminate\Http\Request;

class HealthProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'conditions'        => 'nullable|array',
            'allergies'         => 'nullable|array',
            'sensitivities'     => 'nullable|array',
            'medical_notes'     => 'nullable|string',
            'health_confirmed'  => 'nullable|boolean',
        ]);

        $profile = HealthProfile::updateOrCreate(   // ← changed model
            ['user_id' => $request->user()->id],
            $data
        );

        return response()->json(['saved' => true, 'profile' => $profile]);
    }

    public function show(Request $request)
    {
        $profile = HealthProfile::where('user_id', $request->user()->id)->first();   // ← changed model
        return response()->json($profile ?? []);
    }
}