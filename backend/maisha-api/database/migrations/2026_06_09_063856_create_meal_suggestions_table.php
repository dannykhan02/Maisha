<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->json('ingredient_ids');
            $table->string('meal_name');
            $table->decimal('total_cost_kes', 8, 2);
            $table->decimal('total_calories', 8, 2)->nullable();
            $table->decimal('total_protein_g', 8, 2)->nullable();
            $table->decimal('algorithm_score', 5, 2)->nullable();
            $table->text('explanation')->nullable();
            $table->json('health_notes')->nullable();
            $table->string('ai_provider_used', 50)->nullable();
            $table->decimal('savings_kes', 8, 2)->nullable();
            $table->string('channel')->default('web');
            $table->boolean('accepted')->nullable();
            $table->timestamp('suggested_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_suggestions');
    }
};