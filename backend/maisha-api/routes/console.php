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
// NOTE: This scheduler is registered but NOT YET ACTIVE. To enable the live
// cron job, add the following entry to your system crontab:
//   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
//
// Activation is deferred until after:
// - Item #3 (Flask Rate-Limit Enforcement) is complete
// - Item #7 (Frontend Architecture Audit) is complete
//
// For manual testing, run:
//   php artisan maisha:generate-daily-meal-plans --test-user=1
//
Schedule::command('maisha:generate-daily-meal-plans --test-user=1')
    ->dailyAt('06:00')
    ->timezone('Africa/Nairobi')
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Daily meal plan generation failed');
    });
