<?php
// database/migrations/2026_07_03_120000_drop_user_health_profiles_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_health_profiles');
    }

    public function down(): void
    {
        Schema::create('user_health_profiles', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            // NOTE: original column set not preserved — table was empty
            // and dead at time of drop. Down() exists for migration
            // integrity only, not a faithful restore.
        });
    }
};