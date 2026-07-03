<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_health_profiles', function (Blueprint $table) {
            $table->boolean('health_confirmed')->default(false)->after('medical_notes');
        });
    }

    public function down(): void
    {
        Schema::table('user_health_profiles', function (Blueprint $table) {
            $table->dropColumn('health_confirmed');
        });
    }
};