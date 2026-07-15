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
        Schema::create('vitals_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['bp', 'sugar']);
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->decimal('sugar_value', 5, 1)->nullable();
            $table->enum('sugar_unit', ['mg_dl', 'mmol_l'])->nullable();
            $table->boolean('is_outlier')->default(false);
            $table->enum('recorded_via', ['whatsapp', 'manual'])->default('whatsapp');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['user_id', 'type', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vitals_readings');
    }
};