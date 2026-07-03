<?php
// app/Http/Controllers/MedicationController.php

namespace App\Http\Controllers;

use App\Models\UserMedication;
use App\Services\MedicationDefaultsService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class MedicationController extends Controller
{
    public function __construct(private MedicationDefaultsService $defaults) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'dosage'           => 'nullable|string|max:50',
            'frequency'        => 'required|in:once_daily,twice_daily,three_times_daily,as_needed',
            'food_condition'   => 'required|in:with_food,before_food,after_food,empty_stomach,none',
            'meal_periods'     => 'nullable|array',
            'meal_periods.*'   => 'string|in:morning,midday,evening,bedtime,any',
            'duration_type'    => 'required|in:ongoing,days',
            'duration_days'    => 'nullable|integer|min:1|max:365|required_if:duration_type,days',
            'condition_source' => 'nullable|string|max:50',
            'notes'            => 'nullable|string|max:500',
            'times'            => 'nullable|array',
            'meal_slot_anchor' => 'nullable|string',
            // Day 5 — new
            'estimated_cost_kes'      => 'nullable|numeric|min:0|max:50000',
            'added_during_onboarding' => 'nullable|boolean',
        ]);

        if (empty($data['meal_periods'])) {
            $data['meal_periods'] = $this->defaults->inferMealPeriods($data['frequency']);
        }

        $requiresFood = $this->defaults->requiresFood($data['food_condition'] ?? 'none');

        $expiresOn = null;
        if ($data['duration_type'] === 'days' && !empty($data['duration_days'])) {
            $expiresOn = Carbon::today()->addDays($data['duration_days'])->toDateString();
        }

        $med = UserMedication::create([
            'user_id'                 => $request->user()->id,
            'name'                    => $data['name'],
            'dosage'                  => $data['dosage'] ?? null,
            'frequency'               => $data['frequency'],
            'food_condition'          => $data['food_condition'],
            'meal_periods'            => $data['meal_periods'],
            'duration_type'           => $data['duration_type'],
            'duration_days'           => $data['duration_days'] ?? null,
            'expires_on'              => $expiresOn,
            'condition_source'        => $data['condition_source'] ?? null,
            'is_active'               => true,
            'notes'                   => $data['notes'] ?? null,
            'times'                   => $data['times'] ?? $this->defaults->inferTimes($data['frequency']),
            'requires_food'           => $requiresFood,
            'meal_slot_anchor'        => $data['meal_slot_anchor'] ?? ($data['meal_periods'][0] ?? null),
            'estimated_cost_kes'      => $data['estimated_cost_kes'] ?? null,
            'added_during_onboarding' => $data['added_during_onboarding'] ?? false,
        ]);

        return response()->json(['saved' => true, 'medication' => $med], 201);
    }

    public function list(Request $request)
    {
        $meds = UserMedication::where('user_id', $request->user()->id)
            ->active()
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($meds);
    }

    public function update(Request $request, $id)
    {
        $med = UserMedication::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'name'             => 'sometimes|string|max:100',
            'dosage'           => 'nullable|string|max:50',
            'frequency'        => 'sometimes|in:once_daily,twice_daily,three_times_daily,as_needed',
            'food_condition'   => 'sometimes|in:with_food,before_food,after_food,empty_stomach,none',
            'meal_periods'     => 'nullable|array',
            'meal_periods.*'   => 'string|in:morning,midday,evening,bedtime,any',
            'is_active'        => 'sometimes|boolean',
            'notes'            => 'nullable|string|max:500',
            'estimated_cost_kes' => 'nullable|numeric|min:0|max:50000',
        ]);

        if (isset($data['food_condition'])) {
            $data['requires_food'] = $this->defaults->requiresFood($data['food_condition']);
        }

        $med->update($data);

        return response()->json(['saved' => true, 'medication' => $med]);
    }

    public function destroy(Request $request, $id)
    {
        $med = UserMedication::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        if (!$med) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $med->update(['is_active' => false]);

        return response()->json(['deleted' => true]);
    }
}