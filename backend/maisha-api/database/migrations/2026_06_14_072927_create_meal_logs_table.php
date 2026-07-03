<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->string('slot');
            $table->json('ingredient_ids')->nullable();
            $table->decimal('total_kcal', 8, 2)->default(0);
            $table->decimal('total_cost_kes', 8, 2)->default(0);
            $table->string('logged_via')->default('app'); // app|whatsapp|estimated
            $table->timestamps();

            $table->index(['user_id', 'date', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_logs');
    }
};