<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            if (!Schema::hasColumn('habits', 'habit_direction')) {
                $table->string('habit_direction')->default('build');
            }
            if (!Schema::hasColumn('habits', 'limit_target')) {
                $table->integer('limit_target')->nullable();
            }
            if (!Schema::hasColumn('habits', 'limit_unit')) {
                $table->string('limit_unit', 50)->nullable();
            }
            if (!Schema::hasColumn('habits', 'difficulty')) {
                $table->string('difficulty')->default('easy');
            }
            if (!Schema::hasColumn('habits', 'trigger_time')) {
                $table->string('trigger_time')->nullable();
            }
            if (!Schema::hasColumn('habits', 'duration_estimate')) {
                $table->string('duration_estimate')->nullable();
            }
            if (!Schema::hasColumn('habits', 'frequency')) {
                $table->string('frequency')->default('daily');
            }
            if (!Schema::hasColumn('habits', 'is_keystone')) {
                $table->boolean('is_keystone')->default(false);
            }
            if (!Schema::hasColumn('habits', 'recommended_for_conditions')) {
                $table->json('recommended_for_conditions')->nullable();
            }
            if (!Schema::hasColumn('habits', 'recommended_for_goals')) {
                $table->json('recommended_for_goals')->nullable();
            }
            if (!Schema::hasColumn('habits', 'is_system')) {
                $table->boolean('is_system')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $columns = [
                'habit_direction', 'limit_target', 'limit_unit', 'difficulty',
                'trigger_time', 'duration_estimate', 'frequency', 'is_keystone',
                'recommended_for_conditions', 'recommended_for_goals', 'is_system'
            ];
            $table->dropColumn($columns);
        });
    }
};