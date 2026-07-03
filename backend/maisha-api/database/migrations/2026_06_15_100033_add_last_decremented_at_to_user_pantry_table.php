<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('user_pantry', function (Blueprint $table) {
            $table->timestamp('last_decremented_at')->nullable()->after('quantity');
        });
    }

    public function down()
    {
        Schema::table('user_pantry', function (Blueprint $table) {
            $table->dropColumn('last_decremented_at');
        });
    }
};