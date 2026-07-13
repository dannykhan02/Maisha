<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─────────────────────────────────────────────────────────────────────────────
// Daily Meal Plan Generation (Scheduled)
// ─────────────────────────────────────────────────────────────────────────────
// Item #7 (Frontend Architecture Audit) is now closed — this is the last
// blocker that was gating activation, per PROJECT_STATE.md.
//
// Previously this ran `--test-user=1` only, meaning it never actually
// generated plans for real users regardless of activation status. Fixed
// in GenerateDailyMealPlans: omitting --test-user now runs the batch
// across every onboarded user. --test-user is kept for manual debugging
// of a single account.
//
// STILL REQUIRED before this fires on any real schedule: the system
// crontab entry below must exist on the host. Laravel's scheduler does
// nothing on its own without this:
//   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
//
// For manual testing of a single user:
//   php artisan maisha:generate-daily-meal-plans --test-user=1
// For a full dry run against all onboarded users:
//   php artisan maisha:generate-daily-meal-plans
//
Schedule::command('maisha:generate-daily-meal-plans')
    ->dailyAt('06:00')
    ->timezone('Africa/Nairobi')
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Daily meal plan generation batch failed');
    });