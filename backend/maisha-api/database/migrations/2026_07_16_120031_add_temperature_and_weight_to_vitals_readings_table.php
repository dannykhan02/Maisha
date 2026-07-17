<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vitals_readings', function (Blueprint $table) {
            $table->decimal('temperature_value', 5, 2)->nullable()->after('sugar_unit');
            $table->enum('temperature_unit', ['celsius', 'fahrenheit'])->nullable()->after('temperature_value');

            $table->decimal('weight_value', 6, 2)->nullable()->after('temperature_unit');
            $table->enum('weight_unit', ['kg', 'lbs'])->nullable()->after('weight_value');
        });
    }

    public function down(): void
    {
        Schema::table('vitals_readings', function (Blueprint $table) {
            $table->dropColumn(['temperature_value', 'temperature_unit', 'weight_value', 'weight_unit']);
        });
    }
};