<?php

namespace App\Services;

use App\Models\{Habit, User, UserHabit};
use Carbon\Carbon;

class HabitService
{
    public function autoAssign(User $user): array
    {
        $profile = $user->healthProfile;
        $conditions = $profile ? ($profile->conditions ?? []) : [];
        $primaryGoals = $user->primary_goals ?? [];

        // Map each primary goal to fitness_goal values used in habit seeder
        $goalMapped = array_map([$this, 'mapGoal'], $primaryGoals);

        $allHabits = Habit::where('is_system', true)->get();

        $scored = $allHabits->map(function (Habit $habit) use ($conditions, $goalMapped) {
            $forConditions = $habit->recommended_for_conditions ?? [];
            $forGoals = $habit->recommended_for_goals ?? [];
            $score = 0;

            // Condition match
            if (!empty($conditions) && !empty($forConditions) && array_intersect($conditions, $forConditions)) {
                $score += 2;
            }

            // Goal match: if any primary goal matches habit's recommended goals
            if (!empty($goalMapped) && !empty($forGoals)) {
                foreach ($goalMapped as $goal) {
                    if (in_array($goal, $forGoals)) {
                        $score += 2;
                        break; // only add once per habit
                    }
                }
            }

            // Universal habits get +1 if no other matches
            if (empty($forConditions) && empty($forGoals)) {
                $score += 1;
            }
            return ['habit' => $habit, 'score' => $score];
        })->filter(fn($h) => $h['score'] > 0)
          ->sortByDesc('score')
          ->take(5);

        $assigned = [];
        foreach ($scored->values() as $index => $item) {
            $userHabit = UserHabit::firstOrCreate(
                ['user_id' => $user->id, 'habit_id' => $item['habit']->id],
                [
                    'display_order'  => $index + 1,
                    'status'         => 'active',
                    'started_at'     => Carbon::today(),
                    'current_streak' => 0,
                    'longest_streak' => 0,
                ]
            );
            $assigned[] = array_merge($item['habit']->toArray(), ['user_habit_id' => $userHabit->id]);
        }
        return $assigned;
    }

    private function mapGoal(string $goal): string
    {
        return match ($goal) {
            'lose_weight'      => 'weight_loss',
            'gain_muscle'      => 'muscle_gain',
            'manage_condition' => 'manage_condition',
            'eat_better'       => 'maintain',
            default            => 'maintain',
        };
    }
}