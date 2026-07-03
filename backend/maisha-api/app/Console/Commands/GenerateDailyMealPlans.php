<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UtakulaaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateDailyMealPlans extends Command
{
    protected $signature = 'maisha:generate-daily-meal-plans {--test-user=}';
    protected $description = 'Generate daily meal plans for opted-in users (currently test-user only)';

    public function handle(UtakulaaService $service): int
    {
        $testUserId = $this->option('test-user');

        if (!$testUserId) {
            $this->error('--test-user flag is required. Usage: php artisan maisha:generate-daily-meal-plans --test-user=1');
            return self::FAILURE;
        }

        $user = User::find($testUserId);
        if (!$user) {
            $this->error("User {$testUserId} not found.");
            return self::FAILURE;
        }

        $this->info("Generating meal plan for user {$user->id} ({$user->email})...");

        try {
            // Get user's daily budget (fallback to 150 if not set)
            $budget = (float) ($user->daily_budget_kes ?? 150);

            // Check if user has already hit the daily limit (MAX_UTAKULAA_PER_USER_PER_DAY = 10)
            $todayCount = $user->mealSuggestions()
                ->whereDate('suggested_at', today())
                ->count();

            $maxPerDay = 10; // From Flask config: MAX_UTAKULAA_PER_USER_PER_DAY

            if ($todayCount >= $maxPerDay) {
                $this->warn("User {$user->id} already at daily limit ({$todayCount}/{$maxPerDay})");
                Log::info('Daily meal plan generation skipped', [
                    'user_id' => $user->id,
                    'reason'  => 'daily_limit_reached',
                    'count'   => $todayCount,
                ]);
                return self::SUCCESS;
            }

            // Generate meal plan
            $result = $service->getMealPlan($user, $budget);
            $service->saveSuggestion($user, $result, 'scheduled');

            $this->info("✓ Generated meal plan for user {$user->id}");
            Log::info('Daily meal plan generated', [
                'user_id' => $user->id,
                'budget'  => $budget,
            ]);

            return self::SUCCESS;

        } catch (\RuntimeException $e) {
            // Flask timeout or unavailable
            $this->error("Failed to generate meal plan: {$e->getMessage()}");
            Log::error('Daily meal plan generation failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return self::FAILURE;
        }
    }
}
