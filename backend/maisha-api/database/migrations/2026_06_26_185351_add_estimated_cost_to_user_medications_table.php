<?php
// database/migrations/2026_06_27_000001_add_estimated_cost_to_user_medications_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_medications', function (Blueprint $table) {
            $table->decimal('estimated_cost_kes', 8, 2)->nullable()->after('notes');
            $table->boolean('added_during_onboarding')->default(false)->after('estimated_cost_kes');
        });
    }

    public function down(): void
    {
        Schema::table('user_medications', function (Blueprint $table) {
            $table->dropColumn(['estimated_cost_kes', 'added_during_onboarding']);
        });
    }
};