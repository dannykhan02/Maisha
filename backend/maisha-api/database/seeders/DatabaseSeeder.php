<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            IngredientsSeeder::class,    // 59 ingredients
            QuitHabitsSeeder::class,     // ~21 quit/limit habits
            HabitsSeeder::class,         // ~60 build habits
        ]);
    }
}