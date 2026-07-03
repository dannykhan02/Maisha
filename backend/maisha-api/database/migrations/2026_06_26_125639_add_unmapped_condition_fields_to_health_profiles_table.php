<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('health_profiles', function (Blueprint $table) {
            // Add mapped_condition_tags after the 'conditions' column
            $table->json('mapped_condition_tags')->nullable()->after('conditions');
            
            // Add has_unmapped_condition after 'medical_notes'
            $table->boolean('has_unmapped_condition')->default(false)->after('medical_notes');
            
            // Add condition_classification_status after 'has_unmapped_condition'
            $table->enum('condition_classification_status', ['none', 'pending', 'done', 'failed'])
                  ->default('none')
                  ->after('has_unmapped_condition');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'mapped_condition_tags',
                'has_unmapped_condition',
                'condition_classification_status',
            ]);
        });
    }
};