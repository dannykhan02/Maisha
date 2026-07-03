<?php

namespace App\Http\Controllers;

use App\Models\UserMedication;
use App\Models\WaterDailySummary;
use App\Services\{MedicationDefaultsService, WaterService, HabitService, UtakulaaService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\ClassifyHealthConditionText;

class OnboardingController extends Controller
{
    public function __construct(private MedicationDefaultsService $medDefaults)
    {
        //
    }

    public function stepAbout(Request $request)
    {
        $validated = $request->validate([
            'age'        => 'required|integer|min:1|max:120',
            'weight_kg'  => 'required|numeric|min:20|max:300',
            'height_cm'  => 'required|numeric|min:50|max:250',
            'blood_type' => 'nullable|string|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
        ]);

        $user = $request->user();
        $heightInMeters = $validated['height_cm'] / 100;
        $bmi = round($validated['weight_kg'] / ($heightInMeters * $heightInMeters), 1);

        $user->update([
            'age'        => $validated['age'],
            'weight_kg'  => $validated['weight_kg'],
            'height_cm'  => $validated['height_cm'],
            'blood_type' => $validated['blood_type'] ?? null,
            'bmi'        => $bmi,
            'onboarding_step' => max($user->onboarding_step, 1),
        ]);

        $waterService = app(WaterService::class);
        $waterTarget = $waterService->calculateTarget($user);
        WaterDailySummary::updateOrCreate(
            ['user_id' => $user->id, 'date' => today()],
            ['target_ml' => $waterTarget, 'target_calculation_notes' => $waterService->calculationNotes($user)]
        );

        return response()->json([
            'saved'     => true,
            'next_step' => 1,
            'bmi'       => $bmi,
            'message'   => 'Health metrics saved',
        ]);
    }

    public function step1(Request $request)
    {
        $validated = $request->validate([
            'primary_goals'   => 'required|array',
            'primary_goals.*' => 'string|in:lose_weight,gain_muscle,manage_condition,eat_better',
            'goals'           => 'nullable|array',
            'goals.*'         => 'string|in:track_spending,read_more,journal_daily,build_projects,manage_health,build_fitness',
        ]);

        $user = $request->user();
        $user->primary_goals = $validated['primary_goals'];
        if (isset($validated['goals'])) {
            $user->goals = $validated['goals'];
        }
        $user->onboarding_step = max($user->onboarding_step, 2);
        $user->save();

        return response()->json([
            'saved'     => true,
            'next_step' => 2,
            'message'   => 'Goals saved',
        ]);
    }

    /**
     * Step 2 – Health conditions + medications
     *
     * Now supports free‑text condition input and medication persistence.
     * Medications are saved with default frequency 'once_daily' and meal periods inferred.
     */
    public function step2(Request $request)
    {
        $validated = $request->validate([
            'conditions'           => 'nullable|array',
            'conditions.*'         => 'string|in:diabetes,hypertension,anaemia,high_cholesterol,none',
            'other_condition_text' => 'nullable|string|max:500',
            'medications'                      => 'nullable|array|max:10',
            'medications.*.name'               => 'required_with:medications|string|max:100',
            'medications.*.food_condition'     => 'required_with:medications|in:with_food,before_food,after_food,empty_stomach,none',
            'medications.*.condition_source'   => 'nullable|string|max:50',
            'medications.*.estimated_cost_kes' => 'nullable|numeric|min:0|max:50000',
        ]);

        $conditions = array_values(array_filter($validated['conditions'] ?? [], fn($c) => $c !== 'none'));
        $otherText  = trim($validated['other_condition_text'] ?? '');

        $user = $request->user();

        $user->healthProfile()->update([
            'conditions'                      => $conditions,
            'health_confirmed'                => true,
            'medical_notes'                   => $otherText ?: null,
            'condition_classification_status' => $otherText ? 'pending' : 'none',
        ]);

        if ($otherText) {
            dispatch(new ClassifyHealthConditionText($user->id, $otherText));
        }

        // ─── Save medications from onboarding ─────────────────────────────
        foreach ($validated['medications'] ?? [] as $med) {
            $frequency = 'once_daily'; // onboarding keeps this simple; refine via dashboard later
            $mealPeriods = $this->medDefaults->inferMealPeriods($frequency);

            UserMedication::create([
                'user_id'                 => $user->id,
                'name'                    => $med['name'],
                'frequency'               => $frequency,
                'food_condition'          => $med['food_condition'],
                'meal_periods'            => $mealPeriods,
                'duration_type'           => 'ongoing',
                'condition_source'        => $med['condition_source'] ?? null,
                'is_active'               => true,
                'times'                   => $this->medDefaults->inferTimes($frequency),
                'requires_food'           => $this->medDefaults->requiresFood($med['food_condition']),
                'meal_slot_anchor'        => $mealPeriods[0] ?? null,
                'estimated_cost_kes'      => $med['estimated_cost_kes'] ?? null,
                'added_during_onboarding' => true,
            ]);
        }

        $user->onboarding_step = max($user->onboarding_step, 3);
        $user->save();

        return response()->json([
            'saved'     => true,
            'next_step' => 3,
            'message'   => 'Health info saved',
        ]);
    }

    /**
     * Day 2 + Day 3 surgery.
     *
     * Day 2 adds income_pattern — a new required field.
     * Day 3 adds the custom budget path: users may now send budget_range='custom'
     * plus a custom_amount instead of a preset bucket.
     * The existing bucket-midpoint fallback is unchanged for preset range users.
     */
    public function step3(Request $request)
    {
        $validated = $request->validate([
            // Day 3: 'custom' is now a valid range value
            'budget_range'   => 'required|in:under_100,100_200,200_400,over_400,custom',
            // Day 2: income pattern is required from this step forward
            'income_pattern' => 'required|in:daily,weekly,irregular',
            // Day 3: required only when the user picks custom; min 10 avoids the
            // zero-budget trap in complete() — see bonus flag note below
            'custom_amount'  => 'required_if:budget_range,custom|nullable|numeric|min:10|max:5000',
        ]);

        $budgetMap = [
            'under_100' => 80,
            '100_200'   => 150,
            '200_400'   => 300,
            'over_400'  => 500,
        ];

        $isCustom = $validated['budget_range'] === 'custom';

        // If custom: use the exact amount. If preset: use the midpoint as before.
        $budgetKes = $isCustom
            ? (float) $validated['custom_amount']
            : $budgetMap[$validated['budget_range']];

        $request->user()->update([
            // Day 3: now persisting budget_range (was silently dropped before)
            'budget_range'      => $validated['budget_range'],
            // Day 3: track whether the KES figure came from a custom input
            'budget_is_custom'  => $isCustom,
            // Day 2: new column
            'income_pattern'    => $validated['income_pattern'],
            // Existing column, now potentially set from custom_amount
            'daily_budget_kes'  => $budgetKes,
            // Day 1: mark step 3 complete (step index 4 = budget done)
            'onboarding_step'   => max($request->user()->onboarding_step, 4),
        ]);

        return response()->json([
            'saved'       => true,
            'next_step'   => 'complete',
            'budget_kes'  => $budgetKes,
            'message'     => 'Budget saved',
        ]);
    }

    /**
     * Complete onboarding.
     *
     * BONUS FLAG: changed the budget guard from `!$user->daily_budget_kes`
     * (falsy — trips on 0.0) to `is_null(...)` which is the actual intent.
     * A user with a genuine KES 0 edge case (Day 9) won't be blocked here.
     */
    public function complete(
        Request $request,
        HabitService $habitService,
        WaterService $waterService,
        UtakulaaService $utakulaaService
    ) {
        $user = $request->user();

        if (empty($user->primary_goals) || is_null($user->daily_budget_kes)) {
            return response()->json(['error' => 'Please complete all onboarding steps first'], 422);
        }

        $user->update(['onboarded' => true]);

        $assignedHabits = $habitService->autoAssign($user);
        $waterTarget = $waterService->calculateTarget($user);
        WaterDailySummary::updateOrCreate(
            ['user_id' => $user->id, 'date' => today()],
            ['target_ml' => $waterTarget, 'consumed_ml' => 0, 'target_calculation_notes' => $waterService->calculationNotes($user)]
        );

        $firstSuggestion = null;
        try {
            $result = $utakulaaService->getMealPlan($user, (float) $user->daily_budget_kes);
            $utakulaaService->saveSuggestion($user, $result, 'onboarding');
            $firstSuggestion = $result;
        } catch (\Throwable $e) {
            Log::warning('Flask unavailable during onboarding: ' . $e->getMessage());
        }

        return response()->json([
            'onboarded'        => true,
            'first_suggestion' => $firstSuggestion,
            'assigned_habits'  => $assignedHabits,
            'water_target_ml'  => $waterTarget,
            'water_target_l'   => round($waterTarget / 1000, 1),
            'water_notes'      => $waterService->calculationNotes($user),
            'message'          => 'Welcome to Maisha!',
        ]);
    }

    /**
     * Day 1 — new progress endpoint.
     *
     * Replaces what the frontend was using status() for.
     * Returns resume_at_step so the frontend can jump to the right step
     * on mount rather than always starting at 0.
     *
     * Kept as a separate method so existing status() callers (dashboard,
     * profile page, etc.) are not broken. Add the route alongside status().
     */
    public function progress(Request $request)
    {
        $user = $request->user();
        $profile = $user->healthProfile;

        return response()->json([
            'onboarded'      => (bool) $user->onboarded,
            // null when already fully onboarded; non-null means "resume here"
            'resume_at_step' => $user->onboarded ? null : $user->onboarding_step,
            'data' => [
                'age'              => $user->age,
                'weight_kg'        => $user->weight_kg,
                'height_cm'        => $user->height_cm,
                'blood_type'       => $user->blood_type,
                'bmi'              => $user->bmi,
                'primary_goals'    => $user->primary_goals ?? [],
                'lifestyle_goals'  => $user->goals ?? [],
                'conditions'       => $profile?->conditions ?? [],
                'budget_range'     => $user->budget_range,
                'daily_budget_kes' => $user->daily_budget_kes,
                'income_pattern'   => $user->income_pattern,
            ],
        ]);
    }

    /**
     * Original status() kept intact — do not modify.
     * Anything in the app that already calls GET /onboarding/status
     * keeps working without changes.
     */
    public function status(Request $request)
    {
        $user = $request->user();
        $profile = $user->healthProfile;

        return response()->json([
            'onboarded'       => (bool) $user->onboarded,
            'steps_completed' => [
                'step_about' => !is_null($user->age) && !is_null($user->weight_kg) && !is_null($user->height_cm),
                'step_1'     => !empty($user->primary_goals),
                'step_2'     => (bool) ($profile->health_confirmed ?? false),
                'step_3'     => $user->daily_budget_kes > 0,
            ],
            'data' => [
                'age'              => $user->age,
                'weight_kg'        => $user->weight_kg,
                'height_cm'        => $user->height_cm,
                'blood_type'       => $user->blood_type,
                'bmi'              => $user->bmi,
                'primary_goals'    => $user->primary_goals ?? [],
                'goals'            => $user->goals ?? [],
                'conditions'       => $profile?->conditions ?? [],
                'daily_budget_kes' => $user->daily_budget_kes,
            ],
        ]);
    }
}