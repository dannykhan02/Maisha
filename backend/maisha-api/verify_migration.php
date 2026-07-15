<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Use user ID 1, or fallback to the first user
$user = \App\Models\User::find(1) ?? \App\Models\User::first();

if (!$user) {
    echo "❌ No user found. Please create a user or use an existing ID.\n";
    exit(1);
}

$reading = \App\Models\VitalsReading::create([
    'user_id'     => $user->id,
    'type'        => 'bp',
    'systolic'    => 120,
    'diastolic'   => 80,
    'recorded_at' => now(),
]);

echo "✅ Vitals reading created with ID: " . $reading->id . "\n";
$fresh = $reading->fresh();
print_r($fresh->toArray());
