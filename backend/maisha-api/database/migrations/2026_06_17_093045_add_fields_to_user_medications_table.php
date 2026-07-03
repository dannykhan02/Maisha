<?php
// database/migrations/2026_06_17_000001_add_fields_to_user_medications_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_medications', function (Blueprint $table) {
            $table->string('dosage')->nullable()->after('name');
            $table->string('frequency')->default('once_daily')->after('dosage');
            $table->string('food_condition')->default('none')->after('frequency');
            $table->json('meal_periods')->nullable()->after('food_condition');
            $table->string('duration_type')->default('ongoing')->after('meal_periods');
            $table->unsignedInteger('duration_days')->nullable()->after('duration_type');
            $table->date('expires_on')->nullable()->after('duration_days');
            $table->string('condition_source')->nullable()->after('expires_on');
            $table->boolean('is_active')->default(true)->after('condition_source');
            $table->text('notes')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('user_medications', function (Blueprint $table) {
            $table->dropColumn([
                'dosage', 'frequency', 'food_condition', 'meal_periods',
                'duration_type', 'duration_days', 'expires_on',
                'condition_source', 'is_active', 'notes',
            ]);
        });
    }
};