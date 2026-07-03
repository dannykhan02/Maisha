<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add a new JSON column 'primary_goals' (nullable)
        Schema::table('users', function (Blueprint $table) {
            $table->json('primary_goals')->nullable()->after('primary_goal');
        });

        // Step 2: Migrate existing data: convert old single primary_goal to array
        // If a user had 'lose_weight', we store ['lose_weight']
        DB::table('users')->whereNotNull('primary_goal')->update([
            'primary_goals' => DB::raw("JSON_ARRAY(primary_goal)")
        ]);

        // Step 3: Drop the old column (optional - keep for backward compatibility?)
        // We'll keep it temporarily and later remove. But for clean design, drop it.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('primary_goal');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('primary_goal')->nullable()->after('id');
        });

        // Restore first value from primary_goals if exists
        DB::table('users')->whereNotNull('primary_goals')->update([
            'primary_goal' => DB::raw("JSON_EXTRACT(primary_goals, '$[0]')")
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('primary_goals');
        });
    }
};