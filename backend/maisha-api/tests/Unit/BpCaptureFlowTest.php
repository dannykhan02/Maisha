<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\VitalsReading;
use App\Models\WhatsappConversationState;
use App\Services\BpCaptureFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BpCaptureFlowTest extends TestCase
{
    use RefreshDatabase;

    private BpCaptureFlow $flow;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flow = new BpCaptureFlow();
        $this->user = User::factory()->create();
    }

    private function state(): WhatsappConversationState
    {
        return WhatsappConversationState::where('user_id', $this->user->id)->firstOrFail();
    }

    public function test_start_asks_for_systolic()
    {
        $result = $this->flow->start($this->user->id);
        $this->assertStringContainsString('top number', $result['reply']);
        $this->assertEquals('awaiting_systolic', $this->state()->step);
    }

    public function test_happy_path_two_steps()
    {
        $this->flow->start($this->user->id);
        $r1 = $this->flow->handle($this->state(), '120');
        $this->assertStringContainsString('bottom number', $r1['reply']);

        $r2 = $this->flow->handle($this->state(), '80');
        $this->assertStringContainsString('120/80', $r2['reply']);
        $this->assertTrue($r2['done']);

        $this->assertDatabaseHas('vitals_readings', [
            'user_id' => $this->user->id, 'systolic' => 120, 'diastolic' => 80, 'is_outlier' => false,
        ]);
    }

    public function test_shorthand_pair_accepted_immediately()
    {
        $this->flow->start($this->user->id);
        $r = $this->flow->handle($this->state(), '120/80');
        $this->assertTrue($r['done']);
        $this->assertDatabaseHas('vitals_readings', ['systolic' => 120, 'diastolic' => 80]);
    }

    public function test_non_numeric_input_reasks_same_question()
    {
        $this->flow->start($this->user->id);
        $r = $this->flow->handle($this->state(), 'high');
        $this->assertStringContainsString("doesn't look like a number", $r['reply']);
        $this->assertEquals('awaiting_systolic', $this->state()->step);
    }

    public function test_outlier_value_requires_confirmation()
    {
        $this->flow->start($this->user->id);
        $r = $this->flow->handle($this->state(), '5'); // implausible systolic
        $this->assertStringContainsString('unusual', $r['reply']);
        $this->assertEquals('confirming_systolic_outlier', $this->state()->step);

        // Confirm it
        $r2 = $this->flow->handle($this->state(), 'YES');
        $this->assertEquals('awaiting_diastolic', $this->state()->step);
    }

    public function test_outlier_rejection_reasks_number()
    {
        $this->flow->start($this->user->id);
        $this->flow->handle($this->state(), '5');
        $r = $this->flow->handle($this->state(), 'no thanks');
        $this->assertEquals('awaiting_systolic', $this->state()->step);
    }

    public function test_skip_ends_flow_without_saving()
    {
        $this->flow->start($this->user->id);
        $r = $this->flow->handle($this->state(), 'SKIP');
        $this->assertTrue($r['done']);
        $this->assertDatabaseCount('vitals_readings', 0);
        $this->assertDatabaseCount('whatsapp_conversation_states', 0);
    }

    public function test_outlier_bp_saved_flagged()
    {
        $this->flow->start($this->user->id);
        $this->flow->handle($this->state(), '5');
        $this->flow->handle($this->state(), 'YES');
        $this->flow->handle($this->state(), '80');

        $this->assertDatabaseHas('vitals_readings', [
            'systolic' => 5, 'diastolic' => 80, 'is_outlier' => true,
        ]);
    }
}