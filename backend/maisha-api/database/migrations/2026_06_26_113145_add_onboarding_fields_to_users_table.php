<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Day 1 — track which step the user last completed so we can resume
            // Using unsignedTinyInteger to save space; 0 = not started
            $table->unsignedTinyInteger('onboarding_step')->default(0)->after('onboarded');

            // Day 2 — income cadence, used to tune meal-budget suggestions
            $table->enum('income_pattern', ['daily', 'weekly', 'irregular'])
                  ->nullable()
                  ->after('onboarding_step');

            // Day 3 — persist the raw bucket label the user selected
            // daily_budget_kes already exists as the resolved KES number
            $table->string('budget_range', 20)->nullable()->after('income_pattern');
            $table->boolean('budget_is_custom')->default(false)->after('budget_range');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_step',
                'income_pattern',
                'budget_range',
                'budget_is_custom',
            ]);
        });
    }
};