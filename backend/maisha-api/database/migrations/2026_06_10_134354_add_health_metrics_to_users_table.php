<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('age')->nullable()->after('onboarded');
            $table->decimal('height_cm', 5, 1)->nullable()->after('weight_kg');
            $table->string('blood_type', 5)->nullable()->after('height_cm');
            $table->decimal('bmi', 4, 1)->nullable()->after('blood_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['age', 'height_cm', 'blood_type', 'bmi']);
        });
    }
};