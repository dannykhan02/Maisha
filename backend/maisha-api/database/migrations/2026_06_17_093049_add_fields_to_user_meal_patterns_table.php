<?php
// database/migrations/2026_06_17_000002_add_fields_to_user_meal_patterns_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_meal_patterns', function (Blueprint $table) {
            $table->json('dietary_identity')->nullable()->after('cuisine_preference');
            $table->json('food_dislikes')->nullable()->after('dietary_identity');
            $table->string('budget_split')->default('app_decides')->after('food_dislikes');
            $table->string('cooking_source')->default('both')->after('budget_split');
            $table->string('meal_prep_time')->default('moderate')->after('cooking_source');
            $table->string('protein_preference')->default('any')->after('meal_prep_time');
            $table->json('staple_preference')->nullable()->after('protein_preference');
            $table->boolean('allergies_confirmed')->default(false)->after('staple_preference');
        });
    }

    public function down(): void
    {
        Schema::table('user_meal_patterns', function (Blueprint $table) {
            $table->dropColumn([
                'dietary_identity', 'food_dislikes', 'budget_split',
                'cooking_source', 'meal_prep_time', 'protein_preference',
                'staple_preference', 'allergies_confirmed',
            ]);
        });
    }
};