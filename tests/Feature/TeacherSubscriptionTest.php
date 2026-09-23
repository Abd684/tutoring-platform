<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_teacher_can_subscribe_and_upgrade(): void
    {
        $user = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $monthlyPlan = SubscriptionPlan::create([
            'name' => 'Monthly',
            'price' => 10,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        $yearlyPlan = SubscriptionPlan::create([
            'name' => 'Yearly',
            'price' => 100,
            'billing_cycle' => 'yearly',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/teacher/subscription-plans/{$monthlyPlan->id}/subscribe", [
            'auto_renew' => true,
        ])->assertCreated()
            ->assertJsonPath('data.plan_id', $monthlyPlan->id)
            ->assertJsonPath('data.auto_renew', true);

        $this->postJson("/api/v1/teacher/subscription-plans/{$monthlyPlan->id}/subscribe")
            ->assertConflict();

        $this->postJson("/api/v1/teacher/subscription-plans/{$yearlyPlan->id}/subscribe")
            ->assertOk()
            ->assertJsonPath('data.plan_id', $yearlyPlan->id);

        $this->assertDatabaseHas('teacher_subscriptions', [
            'teacher_id' => $teacher->id,
            'plan_id' => $monthlyPlan->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('teacher_subscriptions', [
            'teacher_id' => $teacher->id,
            'plan_id' => $yearlyPlan->id,
            'status' => 'active',
        ]);
    }
}
