<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds support for pulse-oximeter (SpO2) readings.
     *
     * Only ONE new column is needed: `spo2_value`, blood oxygen
     * saturation as a percentage (0-100). It's genuinely new data with
     * no existing analogue in this table.
     *
     * Pulse rate is deliberately NOT duplicated as `oximeter_pulse`.
     * The oximeter measures the same physiological quantity — heart
     * rate, in bpm — as the `pulse` column already used by BP readings.
     * Reusing it means one source of truth for "what was the person's
     * heart rate at this reading," regardless of which device measured
     * it; `type` still records which device it came from.
     *
     * `type` itself is a plain string column validated in application
     * code (see VitalsReading::booted()), not a DB-level enum, so no
     * schema change is needed there — only the model's allow-list.
     */
    public function up(): void
    {
        Schema::table('vitals_readings', function (Blueprint $table) {
            $table->unsignedTinyInteger('spo2_value')
                ->nullable()
                ->after('weight_unit');
        });
    }

    public function down(): void
    {
        Schema::table('vitals_readings', function (Blueprint $table) {
            $table->dropColumn('spo2_value');
        });
    }
};