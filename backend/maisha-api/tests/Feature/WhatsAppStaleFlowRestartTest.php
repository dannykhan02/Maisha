<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappConversationState;
use App\Services\BpCaptureFlow;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WhatsAppStaleFlowRestartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Service-level regression test: a fresh "bp" trigger while a flow is
     * mid-way through (awaiting_diastolic, with a stale systolic already
     * captured) must discard the stale context and restart cleanly, rather
     * than being rejected as an invalid diastolic number.
     */
    public function test_new_trigger_phrase_restarts_stale_flow(): void
    {
        $user = User::factory()->create();
        $flow = new BpCaptureFlow();

        $flow->start($user->id);
        $flow->handle($this->state($user->id), '130'); // now awaiting_diastolic, context={systolic: 130}

        // Simulate a fresh "bp" trigger arriving mid-flow.
        $result = $flow->handle($this->state($user->id), 'Bp');

        $this->assertStringContainsString('top number', $result['reply']);
        $this->assertFalse($result['done']);

        $state = $this->state($user->id);
        $this->assertEquals('awaiting_systolic', $state->step);
        $this->assertEquals([], $state->context);

        // Now complete the flow with fresh numbers and confirm no bleed-through
        // from the abandoned 130.
        $flow->handle($state, '120');
        $final = $flow->handle($this->state($user->id), '80');

        $this->assertStringContainsString('120/80', $final['reply']);
        $this->assertTrue($final['done']);
    }

    private function state(int $userId): WhatsappConversationState
    {
        return WhatsappConversationState::where('user_id', $userId)->firstOrFail();
    }
}