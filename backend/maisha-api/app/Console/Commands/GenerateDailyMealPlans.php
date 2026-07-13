<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UtakulaaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateDailyMealPlans extends Command
{
    protected $signature = 'maisha:generate-daily-meal-plans {--test-user=}';
    protected $description = 'Generate daily meal plans for all onboarded users (or a single --test-user for manual debugging)';

    private const MAX_PER_DAY = 10; // Must match Flask's MAX_UTAKULAA_PER_USER_PER_HOUR/DAY config

    public function handle(UtakulaaService $service): int
    {
        $testUserId = $this->option('test-user');

        // --test-user is now OPTIONAL. Item #7's audit found this command
        // was previously required to pass it, and routes/console.php's
        // scheduler entry was hardcoded to --test-user=1 — meaning the
        // "daily meal plan" feature only ever ran for one specific user,
        // never for the actual user base. That's fixed here: with no
        // flag, the command now runs for every onboarded user.
        $users = $testUserId
            ? User::where('id', $testUserId)->get()
            : User::where('onboarded', true)->get();

        if ($testUserId && $users->isEmpty()) {
            $this->error("User {$testUserId} not found.");
            return self::FAILURE;
        }

        if ($users->isEmpty()) {
            $this->warn('No onboarded users found — nothing to generate.');
            return self::SUCCESS;
        }

        $succeeded = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($users as $user) {
            $this->info("Generating meal plan for user {$user->id} ({$user->email})...");

            try {
                $budget = (float) ($user->daily_budget_kes ?? 150);

                $todayCount = $user->mealSuggestions()
                    ->whereDate('suggested_at', today())
                    ->count();

                if ($todayCount >= self::MAX_PER_DAY) {
                    $this->warn("  → skipped, already at daily limit ({$todayCount}/" . self::MAX_PER_DAY . ')');
                    Log::info('Daily meal plan generation skipped', [
                        'user_id' => $user->id,
                        'reason'  => 'daily_limit_reached',
                        'count'   => $todayCount,
                    ]);
                    $skipped++;
                    continue;
                }

                $result = $service->getMealPlan($user, $budget);
                $service->saveSuggestion($user, $result, 'scheduled');

                $this->info("  ✓ generated for user {$user->id}");
                Log::info('Daily meal plan generated', [
                    'user_id' => $user->id,
                    'budget'  => $budget,
                ]);
                $succeeded++;

            } catch (\RuntimeException $e) {
                // Flask timeout/unavailable for this user — log and move on
                // to the next user rather than aborting the whole batch.
                $this->error("  ✗ failed for user {$user->id}: {$e->getMessage()}");
                Log::error('Daily meal plan generation failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->info("Done. {$succeeded} generated, {$skipped} skipped (limit), {$failed} failed.");
        Log::info('Daily meal plan generation batch complete', [
            'succeeded' => $succeeded,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'mode'      => $testUserId ? 'single-test-user' : 'all-onboarded-users',
        ]);

        // A batch with some failures still exits SUCCESS — individual
        // per-user failures are expected (Flask hiccups, rate limits) and
        // are logged; only total absence of any successful runs alongside
        // failures is worth a non-zero exit for alerting purposes.
        if ($failed > 0 && $succeeded === 0 && $skipped === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}