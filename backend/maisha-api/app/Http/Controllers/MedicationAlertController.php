<?php
// app/Http/Controllers/MedicationAlertController.php

namespace App\Http\Controllers;

use App\Models\UserMedication;
use App\Models\MealLog;
use Illuminate\Http\Request;
use Carbon\Carbon;

class MedicationAlertController extends Controller
{
    public function due(Request $request)
    {
        $user        = $request->user();
        $now         = Carbon::now();
        $windowEnd   = $now->copy()->addMinutes(15);
        $overdueFrom = $now->copy()->subMinutes(60);

        $medications = UserMedication::where('user_id', $user->id)
            ->active()
            ->get();

        $due = [];

        foreach ($medications as $med) {
            if ($med->frequency === 'as_needed') {
                continue;
            }

            $times = $med->times ?? [];

            foreach ($times as $time) {
                $medTime = Carbon::createFromFormat('H:i', $time)
                    ->setDate($now->year, $now->month, $now->day);

                $isDueNow  = $medTime->between($now, $windowEnd);
                $isOverdue = $medTime->between($overdueFrom, $now);

                if (!$isDueNow && !$isOverdue) {
                    continue;
                }

                $logged = false;
                if ($med->food_condition !== 'none' && $med->food_condition !== 'empty_stomach') {
                    $slot = $med->meal_slot_anchor ?? $this->timeToSlot($time);
                    $logged = MealLog::where('user_id', $user->id)
                        ->whereDate('date', today())
                        ->where('slot', $slot)
                        ->exists();
                }

                if (!$logged) {
                    $due[] = [
                        'id'             => $med->id,
                        'medication'     => $med->name,
                        'dosage'         => $med->dosage,
                        'time'           => $time,
                        'slot'           => $med->meal_slot_anchor,
                        'food_condition' => $med->food_condition,
                        'requires_food'  => $med->requires_food,
                        'status'         => $isOverdue ? 'overdue' : 'due_now',
                        'message'        => $this->buildMessage($med, $time),
                        'priority'       => 'tier_1',
                        'overdue'        => $isOverdue,
                    ];
                }
            }
        }

        return response()->json([
            'due'        => $due,
            'count'      => count($due),
            'checked_at' => $now->toDateTimeString(),
        ]);
    }

    private function buildMessage(UserMedication $med, string $time): string
    {
        $name   = $med->name;
        $dosage = $med->dosage ? " ({$med->dosage})" : '';

        return match($med->food_condition) {
            'with_food'     => "Take {$name}{$dosage} at {$time} — take with food.",
            'before_food'   => "Take {$name}{$dosage} at {$time} — take 30 minutes before your meal.",
            'after_food'    => "Take {$name}{$dosage} at {$time} — take after finishing your meal.",
            'empty_stomach' => "Take {$name}{$dosage} at {$time} — take on an empty stomach, no food for 2 hours.",
            default         => "Time to take {$name}{$dosage} at {$time}.",
        };
    }

    private function timeToSlot(string $time): string
    {
        $hour = (int) explode(':', $time)[0];
        return match(true) {
            $hour >= 5  && $hour < 11 => 'breakfast',
            $hour >= 11 && $hour < 15 => 'lunch',
            $hour >= 15 && $hour < 20 => 'dinner',
            default                   => 'dinner',
        };
    }
}