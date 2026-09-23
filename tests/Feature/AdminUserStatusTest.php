<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_suspend_and_activate_a_user(): void
    {
        $admin = User::factory()->create(['role' => 'company_admin']);
        $user = User::factory()->create(['status' => 'active']);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$user->id}/suspend")
            ->assertOk()
            ->assertJsonPath('user.status', 'suspended');

        $this->patchJson("/api/v1/admin/users/{$user->id}/activate")
            ->assertOk()
            ->assertJsonPath('user.status', 'active');
    }

    public function test_non_admin_cannot_change_user_status(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $user = User::factory()->create();

        Sanctum::actingAs($teacher);

        $this->patchJson("/api/v1/admin/users/{$user->id}/suspend")
            ->assertForbidden();
    }
}
