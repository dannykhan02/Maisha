<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Core identity
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();

            // Contact
            $table->string('phone', 20)->unique()->nullable();
            $table->string('wa_number', 20)->nullable();
            $table->string('institution')->nullable();
            $table->string('role')->default('student');

            // Google OAuth
            $table->string('google_id')->unique()->nullable();
            $table->string('avatar')->nullable();
            $table->string('auth_provider')->default('email');

            // Finance (Step 5 onboarding)
            $table->decimal('daily_budget_kes', 10, 2)->default(0);
            $table->string('budget_strictness')->default('flexible');

            // Goals (Step 3 onboarding)
            $table->string('primary_goal')->nullable();
            $table->string('secondary_goal')->nullable();
            $table->string('goal_timeline')->nullable();

            // Food life (Step 4 onboarding)
            $table->boolean('cooks_at_home')->default(true);
            $table->integer('meals_per_day')->default(3);
            $table->json('foods_loved')->nullable();
            $table->json('foods_avoided')->nullable();
            $table->string('cuisine_preference')->nullable();

            // Body (Step 6 onboarding)
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->string('activity_level')->default('moderate');
            $table->string('exercise_frequency')->nullable();

            // Hydration preferences
            $table->integer('glass_size_ml')->default(250);
            $table->boolean('hydration_reminders')->default(true);

            // WhatsApp preferences (Step 7 onboarding)
            $table->json('whatsapp_nudge_types')->nullable();
            $table->string('nudge_time_preference')->default('morning');

            // Status
            $table->boolean('onboarded')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};