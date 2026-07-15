<?php

namespace App\Services;

use App\Models\VitalsReading;
use App\Models\WhatsappConversationState;

class BpCaptureFlow
{
    private const PLAUSIBLE_RANGES = [
        'systolic'  => [70, 220],
        'diastolic' => [40, 140],
    ];

    private const TRIGGER_PATTERN = '/\b(bp|blood pressure)\b/i';

    /**
     * Entry point — call this when a user with no active state
     * says something that should start the BP flow.
     */
    public function start(int $userId): array
    {
        WhatsappConversationState::updateOrCreate(
            ['user_id' => $userId],
            [
                'flow'       => 'bp_capture',
                'step'       => 'awaiting_systolic',
                'context'    => [],
                'expires_at' => now()->addMinutes(30),
            ]
        );

        return ['reply' => "What's your blood pressure today? Send just the top number (e.g. 120)"];
    }

    /**
     * Called on every message while a bp_capture flow is active.
     * Returns ['reply' => string, 'done' => bool]
     */
    public function handle(WhatsappConversationState $state, string $body): array
    {
        $body = trim($body);

        // Defense-in-depth: if a fresh trigger phrase arrives mid-flow, treat it
        // as "cancel and restart" rather than trying to parse it as flow input.
        // Primary fix lives in the webhook controller's routing (trigger-phrase
        // detection happens before state-continuation there); this is a safety
        // net in case that ordering is ever missed, reverted, or bypassed.
        if (preg_match(self::TRIGGER_PATTERN, $body) && !$this->tryParseBpPair($body)) {
            return $this->start($state->user_id) + ['done' => false];
        }

        if (strtoupper($body) === 'SKIP') {
            $state->delete();
            return ['reply' => "No problem — we'll ask again tomorrow.", 'done' => true];
        }

        // Shorthand: "120/80" or "120 80" sent at any step
        if ($pair = $this->tryParseBpPair($body)) {
            return $this->finish($state, $pair['systolic'], $pair['diastolic']);
        }

        // Outlier confirmation reply ("YES" after we flagged a weird number)
        if ($state->step === 'confirming_systolic_outlier' && $this->isConfirmation($body)) {
            $number = $state->context['pending_systolic'];
            return $this->advanceToSecondNumber($state, $number);
        }
        if ($state->step === 'confirming_diastolic_outlier' && $this->isConfirmation($body)) {
            $systolic  = $state->context['systolic'];
            $diastolic = $state->context['pending_diastolic'];
            return $this->finish($state, $systolic, $diastolic);
        }
        // Any non-YES reply to an outlier confirmation re-asks that same number
        if (str_contains($state->step, '_outlier')) {
            $field = str_contains($state->step, 'systolic') ? 'systolic' : 'diastolic';
            $state->update(['step' => "awaiting_{$field}", 'expires_at' => now()->addMinutes(30)]);
            return ['reply' => "No problem — please resend the {$field} number.", 'done' => false];
        }

        $number = $this->extractPlausibleNumber($body);

        if ($number === null) {
            return ['reply' => "That doesn't look like a number. Please send just digits, like 120", 'done' => false];
        }

        if ($state->step === 'awaiting_systolic') {
            if (!$this->isPlausible('systolic', $number)) {
                $state->update([
                    'step'       => 'confirming_systolic_outlier',
                    'context'    => ['pending_systolic' => $number],
                    'expires_at' => now()->addMinutes(30),
                ]);
                return ['reply' => "That number seems unusual — reply YES to confirm {$number}, or resend a different number.", 'done' => false];
            }
            return $this->advanceToSecondNumber($state, $number);
        }

        if ($state->step === 'awaiting_diastolic') {
            if (!$this->isPlausible('diastolic', $number)) {
                $context = $state->context;
                $context['pending_diastolic'] = $number;
                $state->update([
                    'step'       => 'confirming_diastolic_outlier',
                    'context'    => $context,
                    'expires_at' => now()->addMinutes(30),
                ]);
                return ['reply' => "That number seems unusual — reply YES to confirm {$number}, or resend a different number.", 'done' => false];
            }
            return $this->finish($state, $state->context['systolic'], $number);
        }

        // Unknown step — fail safe rather than loop forever
        $state->delete();
        return ['reply' => "Something went wrong — let's start over. Send your top BP number.", 'done' => true];
    }

    private function advanceToSecondNumber(WhatsappConversationState $state, int $systolic): array
    {
        $state->update([
            'step'       => 'awaiting_diastolic',
            'context'    => ['systolic' => $systolic],
            'expires_at' => now()->addMinutes(30),
        ]);
        return ['reply' => "Got it. Now the bottom number (e.g. 80)", 'done' => false];
    }

    private function finish(WhatsappConversationState $state, int $systolic, int $diastolic): array
    {
        $isOutlier = !$this->isPlausible('systolic', $systolic) || !$this->isPlausible('diastolic', $diastolic);

        VitalsReading::create([
            'user_id'      => $state->user_id,
            'type'         => 'bp',
            'systolic'     => $systolic,
            'diastolic'    => $diastolic,
            'is_outlier'   => $isOutlier,
            'recorded_via' => 'whatsapp',
            'recorded_at'  => now(),
        ]);

        $state->delete();

        return ['reply' => "✅ BP recorded: {$systolic}/{$diastolic}", 'done' => true];
    }

    private function tryParseBpPair(string $body): ?array
    {
        if (preg_match('/^\s*(\d{2,3})\s*[\/\s]\s*(\d{2,3})\s*$/', $body, $m)) {
            return ['systolic' => (int) $m[1], 'diastolic' => (int) $m[2]];
        }
        return null;
    }

    private function extractPlausibleNumber(string $body): ?int
    {
        if (preg_match('/^\s*(\d{1,3})\s*$/', $body, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function isPlausible(string $field, int $value): bool
    {
        [$min, $max] = self::PLAUSIBLE_RANGES[$field];
        return $value >= $min && $value <= $max;
    }

    private function isConfirmation(string $body): bool
    {
        return in_array(strtoupper(trim($body)), ['YES', 'Y', 'CONFIRM']);
    }
}