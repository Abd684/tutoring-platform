<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeacherAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_login_refresh_and_logout(): void
    {
        $user = User::factory()->create([
            'phone' => '0991234567',
            'password_hash' => Hash::make('Password123!'),
            'role' => 'teacher',
            'status' => 'active',
        ]);
        Teacher::create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $login = $this->postJson('/api/v1/teacher/login', [
            'phone' => '0991234567',
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $oldRefreshToken = $login->json('refresh_token');

        $refresh = $this->postJson('/api/v1/teacher/refresh', [
            'refresh_token' => $oldRefreshToken,
        ])->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $this->postJson('/api/v1/teacher/refresh', [
            'refresh_token' => $oldRefreshToken,
        ])->assertUnauthorized();

        $this->withToken($refresh->json('access_token'))
            ->postJson('/api/v1/teacher/logout', [
                'refresh_token' => $refresh->json('refresh_token'),
            ])->assertOk();

        $this->assertSame(2, RefreshToken::query()->whereNotNull('revoked_at')->count());
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->postJson('/api/v1/teacher/login', [
            'phone' => '0999999999',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }
}
