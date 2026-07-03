<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase 1: Schema Consolidation
     * Make deprecated fields nullable to prepare for eventual removal.
     * These fields are no longer read by core logic (UtakulaaService, controllers).
     */
    public function up(): void
    {
        // User.activity_level → DEPRECATED: use UserActivityProfile.activity_level
        Schema::table('users', function (Blueprint $table) {
            $table->string('activity_level')->nullable()->change();
        });

        // User.meals_per_day → DEPRECATED: use UserMealPattern.meals_per_day
        Schema::table('users', function (Blueprint $table) {
            $table->integer('meals_per_day')->nullable()->change();
        });

        // HealthProfile.medications → DEPRECATED: use UserMedication table
        Schema::table('health_profiles', function (Blueprint $table) {
            $table->json('medications')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('activity_level')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('meals_per_day')->nullable(false)->change();
        });

        Schema::table('health_profiles', function (Blueprint $table) {
            $table->json('medications')->nullable(false)->change();
        });
    }
};
