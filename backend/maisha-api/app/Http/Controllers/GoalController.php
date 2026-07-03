<?php
namespace App\Http\Controllers;
use App\Models\UserGoal;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'primary_goal'     => 'required|string',
            'secondary_goals'  => 'nullable|array',
            'target_weight_kg' => 'nullable|numeric',
            'timeline_weeks'   => 'nullable|integer',
        ]);

        $goal = UserGoal::updateOrCreate(
            ['user_id' => $request->user()->id],
            $data
        );

        // Keep User.primary_goals in sync — this is the field every
        // other read path in the app actually uses (OnboardingController,
        // ProfileCompletionController, Dashboard.tsx, Flask payload).
        // See PROJECT_STATE.md Item #4 for why this exists.
        $request->user()->update([
            'primary_goals' => array_values(array_unique(array_merge(
                [$data['primary_goal']],
                $data['secondary_goals'] ?? []
            ))),
        ]);

        return response()->json(['saved' => true, 'goal' => $goal]);
    }

    public function show(Request $request)
    {
        $goal = UserGoal::where('user_id', $request->user()->id)->first();
        return response()->json($goal ?? []);
    }
}
