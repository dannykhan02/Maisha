<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('water_logs');
        Schema::dropIfExists('hydration_logs');
    }

    public function down(): void
    {
        // Both tables were empty (0 rows) and had no live write path in
        // the app at time of drop — see PROJECT_STATE.md Item #4.
        // Not recreating schema faithfully.
    }
};
