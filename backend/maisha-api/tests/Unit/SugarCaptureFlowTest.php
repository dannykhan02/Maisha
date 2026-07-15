<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\WhatsappConversationState;
use App\Services\SugarCaptureFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SugarCaptureFlowTest extends TestCase
{
    use RefreshDatabase;

    private SugarCaptureFlow $flow;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flow = new SugarCaptureFlow();
        $this->user = User::factory()->create();
    }

    private function state(): WhatsappConversationState
    {
        return WhatsappConversationState::where('user_id', $this->user->id)->firstOrFail();
    }

    public function test_happy_path()
    {
        $this->flow->start($this->user->id);
        $r = $this->flow->handle($this->state(), '140');
        $this->assertStringContainsString('140', $r['reply']);
        $this->assertTrue($r['done']);
        $this->assertDatabaseHas('vitals_readings', ['sugar_value' => 140, 'is_outlier' => false]);
    }

    public function test_non_numeric_reasks()
    {
        $this->flow->start($this->user->id);
        $r = $this->flow->handle($this->state(), 'low');
        $this->assertStringContainsString("doesn't look like a number", $r['reply']);
    }

    public function test_outlier_confirmation_flow()
    {
        $this->flow->start($this->user->id);
        $this->flow->handle($this->state(), '900');
        $r = $this->flow->handle($this->state(), 'YES');
        $this->assertTrue($r['done']);
        $this->assertDatabaseHas('vitals_readings', ['sugar_value' => 900, 'is_outlier' => true]);
    }

    public function test_skip()
    {
        $this->flow->start($this->user->id);
        $r = $this->flow->handle($this->state(), 'SKIP');
        $this->assertTrue($r['done']);
        $this->assertDatabaseCount('vitals_readings', 0);
    }
}