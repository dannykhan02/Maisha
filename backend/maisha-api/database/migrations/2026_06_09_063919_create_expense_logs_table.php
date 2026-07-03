<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('budget_log_id')->constrained()->onDelete('cascade');
            $table->decimal('amount_kes', 8, 2);
            $table->string('description', 255)->nullable();
            $table->foreignId('meal_suggestion_id')
                  ->nullable()
                  ->constrained()
                  ->onDelete('set null');
            $table->string('logged_via')->default('web');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_logs');
    }
};