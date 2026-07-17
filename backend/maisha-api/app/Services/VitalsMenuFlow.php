<?php

namespace App\Services;

use App\Models\WhatsappConversationState;

/**
 * Primary, accessibility-first entry point into vitals capture. Rather than
 * requiring a user to already know and type a medical term ("bp", "sugar"),
 * this presents a numbered menu — the same interaction pattern used by
 * M-Pesa/USSD services, which the target user base is already fluent in
 * regardless of health literacy or English medical vocabulary.
 *
 * Jargon-word shortcuts ("bp", "blood pressure", "sugar", etc.) are NOT
 * handled here and deliberately so — that routing decision belongs at the
 * webhook-controller trigger-detection layer, one level up, so it stays a
 * cheap, reversible mapping choice ("does 'bp' skip straight to
 * BpCaptureFlow, or land here on the menu first?") rather than something
 * baked into this class. This class only needs to handle numeric menu
 * replies once a user is already in the menu.
 *
 * On a valid selection, this hands off to the chosen flow's start() method,
 * which overwrites this same WhatsappConversationState row via
 * updateOrCreate(['user_id' => ...]) — so the transition from menu-state to
 * sub-flow-state is a single atomic state update, not a separate delete
 * step.
 */
class VitalsMenuFlow
{
    private const OPTIONS = [
        '1' => ['flow' => BpCaptureFlow::class, 'label' => 'Blood pressure'],
        '2' => ['flow' => SugarCaptureFlow::class, 'label' => 'Blood sugar'],
        '3' => ['flow' => TemperatureCaptureFlow::class, 'label' => 'Temperature'],
        '4' => ['flow' => WeightCaptureFlow::class, 'label' => 'Weight'],
    ];

    public function start(int $userId): array
    {
        WhatsappConversationState::updateOrCreate(
            ['user_id' => $userId],
            [
                'flow'       => 'vitals_menu',
                'step'       => 'awaiting_selection',
                'context'    => [],
                'expires_at' => now()->addMinutes(30),
            ]
        );

        return ['reply' => $this->menuText()];
    }

    public function handle(WhatsappConversationState $state, string $body): array
    {
        $body = trim($body);

        if (strtoupper($body) === 'SKIP') {
            $state->delete();
            return ['reply' => "No problem — we'll check in again tomorrow.", 'done' => true];
        }

        $choice = self::OPTIONS[$body] ?? null;

        if ($choice === null) {
            return [
                'reply' => "Please reply with a number from the list, or SKIP.\n\n" . $this->menuText(),
                'done'  => false,
            ];
        }

        /** @var object{start: callable} $flow */
        $flow = app($choice['flow']);
        $result = $flow->start($state->user_id);

        return ['reply' => $result['reply'], 'done' => false];
    }

    private function menuText(): string
    {
        $lines = ["Let's check in on your health. What would you like to record?", ''];

        foreach (self::OPTIONS as $number => $option) {
            $lines[] = "{$number}. {$option['label']}";
        }

        $lines[] = '';
        $lines[] = 'Reply with a number, or type SKIP';

        return implode("\n", $lines);
    }
}