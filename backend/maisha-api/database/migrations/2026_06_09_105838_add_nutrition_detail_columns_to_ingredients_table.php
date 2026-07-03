<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('sodium_mg', 8, 2)->nullable()->after('fibre_g');
            $table->decimal('potassium_mg', 8, 2)->nullable()->after('sodium_mg');
            $table->decimal('iron_mg', 8, 2)->nullable()->after('potassium_mg');
            $table->decimal('vitamin_c_mg', 8, 2)->nullable()->after('iron_mg');
            $table->unsignedSmallInteger('glycaemic_index')->nullable()->after('vitamin_c_mg');
            $table->json('peak_months')->nullable()->after('glycaemic_index');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn([
                'sodium_mg', 'potassium_mg', 'iron_mg',
                'vitamin_c_mg', 'glycaemic_index', 'peak_months'
            ]);
        });
    }
};