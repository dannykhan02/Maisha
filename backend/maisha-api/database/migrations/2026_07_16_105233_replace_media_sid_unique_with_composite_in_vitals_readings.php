<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original unique(media_sid) constraint is wrong: ProcessIncomingPhoto
     * intentionally saves TWO rows (one 'bp', one 'sugar') from a single photo
     * when both a BP monitor and a glucometer are visible in the same image —
     * both rows share the same media_sid by design. A single-column unique
     * constraint on media_sid makes the second insert fail every time this
     * happens, which was silently killing the WhatsApp reply (the job crashes
     * before reaching sendWhatsAppReply()). The correct constraint is
     * unique per (media_sid, type) — still prevents true duplicate inserts
     * for the same reading type from the same photo (e.g. job retries),
     * while allowing one bp row and one sugar row from the same media_sid.
     */
    public function up(): void
    {
        Schema::table('vitals_readings', function (Blueprint $table) {
            $table->dropUnique('vitals_readings_media_sid_unique');
            $table->unique(['media_sid', 'type'], 'vitals_readings_media_sid_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vitals_readings', function (Blueprint $table) {
            $table->dropUnique('vitals_readings_media_sid_type_unique');
            $table->unique('media_sid', 'vitals_readings_media_sid_unique');
        });
    }
};