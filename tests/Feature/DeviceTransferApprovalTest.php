<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceTransferApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active', 'password_hash' => Hash::make('Password123!')]);
        Student::create(['user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    private function login(User $user, string $uuid = 'old-device'): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!',
            'device_uuid' => $uuid, 'platform' => 'android'])->assertOk()->json();
    }

    private function requestTransfer(User $user, string $uuid = 'new-device'): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/device/transfer', ['email' => $user->email, 'password' => 'Password123!',
            'device_uuid' => $uuid, 'platform' => 'android'])->assertStatus(202)->assertJsonPath('data.status', 'pending')->json('data');
    }

    private function authenticated(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_approval_revokes_old_tokens_and_refresh_and_claim_is_single_use(): void
    {
        $user = $this->student();
        $login = $this->login($user);
        $transfer = $this->requestTransfer($user);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'device_transfer_requested']);
        $this->authenticated($login['access_token'])->getJson('/api/v1/student/device-transfer-requests')->assertOk()->assertJsonPath('data.0.id', $transfer['id']);
        $this->authenticated($login['access_token'])->postJson('/api/v1/student/device-transfer-requests/'.$transfer['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->authenticated($login['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login['refresh_token'], 'device_uuid' => 'old-device'])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $claimed = $this->withHeader('X-Transfer-Token', $transfer['transfer_token'])->postJson('/api/v1/auth/device/transfer-requests/'.$transfer['id'].'/claim')
            ->assertOk()->assertJsonPath('device.device_uuid', 'new-device')->json('access_token');
        $this->authenticated($claimed)->getJson('/api/v1/me')->assertOk();
        $this->postJson('/api/v1/auth/device/transfer-requests/'.$transfer['id'].'/claim')->assertConflict();
        $this->assertSame(1, StudentDevice::where('student_id', $user->student->id)->where('status', 'active')->count());
    }

    public function test_rejection_preserves_old_session_and_never_auto_transfers(): void
    {
        $user = $this->student();
        $login = $this->login($user);
        $transfer = $this->requestTransfer($user);
        $this->authenticated($login['access_token'])->postJson('/api/v1/student/device-transfer-requests/'.$transfer['id'].'/reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->authenticated($login['access_token'])->getJson('/api/v1/me')->assertOk();
        $this->travel(73)->hours();
        $this->artisan('devices:resolve-transfers')->assertSuccessful();
        $this->withHeader('X-Transfer-Token', $transfer['transfer_token'])->postJson('/api/v1/auth/device/transfer-requests/'.$transfer['id'].'/claim')->assertConflict();
        $this->assertDatabaseHas('device_transfer_requests', ['id' => $transfer['id'], 'status' => 'rejected']);
    }

    public function test_deadline_auto_approves_once_and_early_poll_does_not_transfer(): void
    {
        $user = $this->student();
        $login = $this->login($user);
        $transfer = $this->requestTransfer($user);
        $this->withHeader('X-Transfer-Token', 'wrong')->getJson('/api/v1/auth/device/transfer-requests/'.$transfer['id'])->assertNotFound();
        $this->withHeader('X-Transfer-Token', $transfer['transfer_token'])->getJson('/api/v1/auth/device/transfer-requests/'.$transfer['id'])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/v1/auth/device/transfer-requests/'.$transfer['id'].'/claim')->assertConflict();
        $this->travel(72)->hours();
        $this->artisan('devices:resolve-transfers')->assertSuccessful();
        $this->artisan('devices:resolve-transfers')->assertSuccessful();
        $this->assertDatabaseHas('device_transfer_requests', ['id' => $transfer['id'], 'status' => 'auto_approved']);
        $this->postJson('/api/v1/auth/device/transfer-requests/'.$transfer['id'].'/claim')->assertOk();
        $this->assertDatabaseCount('device_events', 5);
    }

    public function test_only_source_account_can_decide_and_duplicate_pending_is_rejected(): void
    {
        $user = $this->student();
        $this->login($user);
        $transfer = $this->requestTransfer($user);
        $this->postJson('/api/v1/auth/device/transfer', ['email' => $user->email, 'password' => 'Password123!', 'device_uuid' => 'another-device', 'platform' => 'android'])->assertConflict();
        $other = $this->student();
        $otherLogin = $this->login($other, 'other-device');
        $this->authenticated($otherLogin['access_token'])->postJson('/api/v1/student/device-transfer-requests/'.$transfer['id'].'/approve')->assertNotFound();
        $this->assertDatabaseHas('device_transfer_requests', ['id' => $transfer['id'], 'status' => 'pending']);
    }

    public function test_same_device_supports_multiple_students_and_revoked_sessions_are_rejected(): void
    {
        $first = $this->student();
        $second = $this->student();
        $one = $this->login($first, 'shared-device');
        $two = $this->login($second, 'shared-device');
        $this->authenticated($one['access_token'])->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $first->id);
        $this->authenticated($two['access_token'])->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $second->id);
        DeviceSession::whereHas('studentDevice', fn ($q) => $q->where('student_id', $first->student->id))->update(['status' => 'revoked']);
        $this->authenticated($one['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->authenticated($two['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_blocked_target_or_suspended_account_cancels_without_revoking_source(): void
    {
        $user = $this->student();
        $login = $this->login($user);
        $transfer = $this->requestTransfer($user);
        Device::where('device_uuid', 'new-device')->update(['status' => 'blocked']);
        $this->authenticated($login['access_token'])->postJson('/api/v1/student/device-transfer-requests/'.$transfer['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->authenticated($login['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_one_physical_device_can_be_shared_by_two_students(): void
    {
        $first = $this->student();
        $second = $this->student();
        $this->login($first, 'shared-phone');
        $this->login($second, 'shared-phone');
        $this->assertSame(1, Device::where('device_uuid', 'shared-phone')->count());
        $this->assertSame(2, StudentDevice::where('status', 'active')->where('is_active', true)->count());
    }

    public function test_return_transfer_reactivates_old_binding_and_revokes_new_device_tokens(): void
    {
        $user = $this->student();
        $old = $this->login($user, 'phone-a');
        $transfer = $this->requestTransfer($user, 'phone-b');
        $this->authenticated($old['access_token'])->postJson('/api/v1/student/device-transfer-requests/'.$transfer['id'].'/approve')->assertOk();
        $this->app['auth']->forgetGuards();
        $new = $this->withHeader('X-Transfer-Token', $transfer['transfer_token'])
            ->postJson('/api/v1/auth/device/transfer-requests/'.$transfer['id'].'/claim')->assertOk()->json('access_token');
        // Login on a revoked binding must request transfer, rather than permanently banning the device.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123!', 'device_uuid' => 'phone-a', 'platform' => 'android'])
            ->assertConflict()->assertJsonPath('code', 'DEVICE_TRANSFER_REQUIRED');
        $back = $this->requestTransfer($user, 'phone-a');
        $this->authenticated($new)->postJson('/api/v1/student/device-transfer-requests/'.$back['id'].'/approve')->assertOk();
        $this->authenticated($new)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Transfer-Token', $back['transfer_token'])->postJson('/api/v1/auth/device/transfer-requests/'.$back['id'].'/claim')
            ->assertOk()->assertJsonPath('device.device_uuid', 'phone-a');
        $this->assertSame(1, StudentDevice::where('student_id', $user->student->id)->where('status', 'active')->where('is_active', true)->count());
    }

    public function test_blocked_device_cannot_login_or_receive_a_transfer(): void
    {
        $user = $this->student();
        $this->login($user, 'safe-phone');
        Device::create(['device_uuid' => 'blocked-phone', 'platform' => 'android', 'status' => 'blocked']);
        $body = ['email' => $user->email, 'password' => 'Password123!', 'device_uuid' => 'blocked-phone', 'platform' => 'android'];
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', $body)->assertForbidden()->assertJsonPath('code', 'DEVICE_BLOCKED');
        $this->postJson('/api/v1/auth/device/transfer', $body)->assertForbidden()->assertJsonPath('code', 'DEVICE_BLOCKED');
    }

    public function test_database_rejects_a_second_active_binding_even_without_the_service(): void
    {
        $user = $this->student();
        $this->login($user);
        $device = Device::create(['device_uuid' => 'other-phone', 'platform' => 'android', 'status' => 'trusted']);
        $this->expectException(QueryException::class);
        StudentDevice::create(['student_id' => $user->student->id, 'device_id' => $device->id, 'status' => 'active', 'is_active' => true]);
    }
}
