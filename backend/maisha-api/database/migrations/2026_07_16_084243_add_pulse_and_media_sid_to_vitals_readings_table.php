<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vitals_readings', function (Blueprint $table) {
            if (!Schema::hasColumn('vitals_readings', 'pulse')) {
                $table->integer('pulse')->nullable()->after('diastolic');
            }
            if (!Schema::hasColumn('vitals_readings', 'sugar_unit')) {
                $table->string('sugar_unit')->nullable()->after('sugar_value');
            }
            // NOTE: is_outlier and recorded_via already exist in the base
            // migration (recorded_via is an enum('whatsapp','manual')) — not
            // touched here. Photo-derived readings use recorded_via='whatsapp'
            // (it genuinely is via WhatsApp) and are distinguished from
            // typed readings by media_sid being non-null, rather than adding
            // a third enum value.

            // Dedupe key for photo-derived readings, mirroring the pattern
            // used in medication_extraction_reviews — prevents the same
            // WhatsApp media message being processed twice.
            if (!Schema::hasColumn('vitals_readings', 'media_sid')) {
                $table->string('media_sid')->nullable()->unique()->after('recorded_via');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vitals_readings', function (Blueprint $table) {
            // Only drop columns THIS migration added — is_outlier and
            // recorded_via belong to the base migration and must not be
            // dropped here.
            foreach (['pulse', 'media_sid'] as $column) {
                if (Schema::hasColumn('vitals_readings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};