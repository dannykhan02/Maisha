<?php

namespace App\Http\Controllers;

use App\Models\UserGoal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    /**
     * The vocabulary here MUST stay in sync with:
     *   frontend/src/lib/goalOptions.ts  (PRIMARY_GOALS)
     * Both were previously defined independently — the frontend's array
     * lived only inside Onboarding.tsx and this controller had its own
     * ad-hoc 'required|string' rule with no enum check at all, so a typo
     * or renamed frontend value would have silently created junk goals.
     * See goalOptions.ts for the single source of truth for labels.
     */
    private const VALID_GOALS = [
        'lose_weight',
        'gain_muscle',
        'manage_condition',
        'eat_better',
    ];

    /**
     * Update the user's tracked goal (target weight + timeline) and keep
     * User.primary_goals in sync, since every other read path in the app
     * (OnboardingController, ProfileCompletionController, Dashboard.tsx,
     * the Flask meal-generation payload) reads from primary_goals, not
     * from this table directly.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'primary_goal'     => ['required', 'string', Rule::in(self::VALID_GOALS)],
            'secondary_goals'  => ['nullable', 'array'],
            'secondary_goals.*' => ['string', Rule::in(self::VALID_GOALS)],
            'target_weight_kg' => ['nullable', 'numeric', 'min:20', 'max:300'],
            'timeline_weeks'   => ['nullable', 'integer', 'min:1', 'max:104'],
        ]);

        // target_weight_kg / timeline_weeks only make sense for goals that
        // are actually about a weight trajectory. Silently accepting them
        // for e.g. 'manage_condition' would let the dashboard show a
        // meaningless "target: 65kg in 8 weeks" card for someone managing
        // diabetes. Reject rather than silently drop, so the frontend gets
        // a clear signal instead of guessing why its value didn't save.
        $weightTrackingGoals = ['lose_weight', 'gain_muscle'];
        if (
            (!is_null($data['target_weight_kg'] ?? null) || !is_null($data['timeline_weeks'] ?? null))
            && !in_array($data['primary_goal'], $weightTrackingGoals, true)
        ) {
            return response()->json([
                'message' => 'target_weight_kg and timeline_weeks are only applicable to lose_weight or gain_muscle goals.',
            ], 422);
        }

        $goal = UserGoal::updateOrCreate(
            ['user_id' => $request->user()->id],
            $data
        );

        $request->user()->update([
            'primary_goals' => array_values(array_unique(array_merge(
                [$data['primary_goal']],
                $data['secondary_goals'] ?? []
            ))),
        ]);

        return response()->json([
            'saved' => true,
            'goal'  => $goal,
        ]);
    }

    /**
     * Returns the tracked goal plus the user's current weight, so the
     * frontend can render progress (current -> target) without a second
     * round trip to /me.
     */
    public function show(Request $request)
    {
        $goal = UserGoal::where('user_id', $request->user()->id)->first();

        return response()->json([
            'goal'              => $goal,
            'current_weight_kg' => $request->user()->weight_kg,
        ]);
    }
}