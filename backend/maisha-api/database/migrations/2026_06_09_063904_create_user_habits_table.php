<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_habits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('habit_id')->constrained()->onDelete('cascade');
            $table->integer('display_order')->default(0);
            $table->integer('current_streak')->default(0);
            $table->integer('longest_streak')->default(0);
            $table->string('status')->default('active');
            $table->date('started_at');
            $table->date('last_completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'habit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_habits');
    }
};