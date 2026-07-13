<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');
        return $user;
    }

    public function test_update_creates_goal_and_syncs_primary_goals(): void
    {
        $user = $this->actingUser();

        $response = $this->postJson('/api/profile/goals', [
            'primary_goal'     => 'lose_weight',
            'secondary_goals'  => ['eat_better'],
            'target_weight_kg' => 68,
            'timeline_weeks'   => 12,
        ]);

        $response->assertOk();
        $response->assertJson(['saved' => true]);

        $this->assertDatabaseHas('user_goals', [
            'user_id'          => $user->id,
            'primary_goal'     => 'lose_weight',
            'target_weight_kg' => 68,
            'timeline_weeks'   => 12,
        ]);

        $user->refresh();
        $this->assertEqualsCanonicalizing(
            ['lose_weight', 'eat_better'],
            $user->primary_goals
        );
    }

    public function test_update_is_idempotent_via_update_or_create(): void
    {
        $user = $this->actingUser();

        $this->postJson('/api/profile/goals', ['primary_goal' => 'lose_weight'])->assertOk();
        $this->postJson('/api/profile/goals', ['primary_goal' => 'gain_muscle'])->assertOk();

        $this->assertSame(1, UserGoal::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('user_goals', [
            'user_id'      => $user->id,
            'primary_goal' => 'gain_muscle',
        ]);
    }

    public function test_rejects_invalid_primary_goal(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/profile/goals', [
            'primary_goal' => 'become_a_wizard',
        ]);

        $response->assertStatus(422);
    }

    public function test_rejects_target_weight_on_non_weight_tracking_goal(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/profile/goals', [
            'primary_goal'     => 'manage_condition',
            'target_weight_kg' => 68,
        ]);

        $response->assertStatus(422);
    }

    public function test_allows_target_weight_on_gain_muscle_goal(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/profile/goals', [
            'primary_goal'     => 'gain_muscle',
            'target_weight_kg' => 82,
            'timeline_weeks'   => 20,
        ]);

        $response->assertOk();
    }

    public function test_show_returns_goal_and_current_weight(): void
    {
        $user = $this->actingUser();
        $user->update(['weight_kg' => 74]);

        UserGoal::create([
            'user_id'          => $user->id,
            'primary_goal'     => 'lose_weight',
            'target_weight_kg' => 68,
            'timeline_weeks'   => 12,
        ]);

        $response = $this->getJson('/api/profile/goals');

        $response->assertOk();
        $response->assertJsonPath('goal.primary_goal', 'lose_weight');
        $response->assertJsonPath('current_weight_kg', '74.00');
    }

    public function test_show_returns_null_goal_when_none_set(): void
    {
        $this->actingUser();

        $response = $this->getJson('/api/profile/goals');

        $response->assertOk();
        $response->assertJsonPath('goal', null);
    }
}