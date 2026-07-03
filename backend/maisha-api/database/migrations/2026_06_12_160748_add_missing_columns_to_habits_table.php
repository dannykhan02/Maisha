<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            if (!Schema::hasColumn('habits', 'name')) {
                $table->string('name')->after('id');
            }
            if (!Schema::hasColumn('habits', 'name_sw')) {
                $table->string('name_sw')->nullable()->after('name');
            }
            if (!Schema::hasColumn('habits', 'category')) {
                $table->string('category')->nullable()->after('name_sw');
            }
        });
    }

    public function down(): void
    {
        Schema::table('habits', function (Blueprint $table) {
            $table->dropColumn(['name', 'name_sw', 'category']);
        });
    }
};