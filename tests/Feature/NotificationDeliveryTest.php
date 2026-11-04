<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationPush;
use App\Jobs\DispatchCampaignPush;
use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\PushRegistration;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\Teacher;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\NotificationCampaigns;
use App\Services\PushRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['notifications.push_enabled' => false, 'services.firebase.project_id' => 'notification-test']);
    }

    private function teacher(): User
    {
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    private function registration(User $user): PushRegistration
    {
        $token = 'test-only-device-token-'.bin2hex(random_bytes(16));

        return PushRegistration::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token),
            'token_ciphertext' => $token, 'platform' => 'android', 'is_active' => true, 'last_seen_at' => now()]);
    }

    private function campaign(array $extra = []): NotificationCampaign
    {
        return NotificationCampaign::create(array_replace(['target_scope' => 'all', 'title' => 'Announcement', 'body' => 'Message',
            'status' => 'dispatched', 'dispatched_at' => now()], $extra));
    }

    private function personal(User $user): Notification
    {
        return Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => 'Personal', 'body' => 'Message'])->fresh();
    }

    private function prepareTransport(int $status = 200, array $body = ['name' => 'projects/test/messages/test'], array $headers = []): void
    {
        config(['notifications.push_enabled' => true]);
        Cache::put('firebase:fcm:oauth:'.hash('sha256', 'notification-test'), 'test-oauth-token', 600);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://fcm.googleapis.com/*' => Http::response($body, $status, $headers)]);
    }

    public function test_registration_is_encrypted_bounded_scoped_and_cannot_claim_another_accounts_token(): void
    {
        $teacher = $this->teacher();
        Sanctum::actingAs($teacher);
        $token = str_repeat('secret-device-token', 3);
        $body = ['fcm_token' => $token, 'platform' => 'android'];
        $this->postJson('/api/v1/push-tokens', $body)->assertOk()->assertJsonPath('data.registered', true);
        $registration = PushRegistration::firstOrFail();
        $this->assertNotSame($token, $registration->getRawOriginal('token_ciphertext'));
        $this->assertSame($token, $registration->token_ciphertext);
        $this->assertArrayNotHasKey('token_ciphertext', $registration->toArray());
        $this->postJson('/api/v1/push-tokens', $body)->assertOk();
        $this->assertDatabaseCount('push_registrations', 1);
        $this->postJson('/api/v1/push-tokens', $body + ['user_id' => 999])->assertUnprocessable();
        Sanctum::actingAs($this->teacher());
        $this->postJson('/api/v1/push-tokens', $body)->assertConflict();
        $this->deleteJson('/api/v1/push-tokens', ['fcm_token' => $token])->assertOk();
        $this->assertDatabaseCount('push_registrations', 1);
        Sanctum::actingAs($teacher);
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/v1/push-tokens', ['fcm_token' => $token.$i, 'platform' => 'android'])->assertOk();
        }
        $this->assertDatabaseCount('push_registrations', 5);
    }

    public function test_student_registration_requires_real_active_bound_device_session(): void
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $user->id, 'status' => 'active']);
        $device = Device::create(['device_uuid' => 'notification-device', 'platform' => 'android', 'status' => 'trusted']);
        $binding = StudentDevice::create(['student_id' => $student->id, 'device_id' => $device->id, 'status' => 'active', 'is_active' => true]);
        $session = DeviceSession::create(['student_device_id' => $binding->id, 'refresh_token_hash' => hash('sha256', 'qa'), 'status' => 'active', 'expires_at' => now()->addDay()]);
        $token = $user->createToken('student_device_'.$binding->id.'_session_'.$session->id)->plainTextToken;
        $body = ['fcm_token' => str_repeat('test-student-token', 3), 'platform' => 'android'];
        $this->withToken($token)->postJson('/api/v1/push-tokens', $body)->assertOk();
        $registration = PushRegistration::firstOrFail();
        $this->assertSame($device->id, $registration->device_id);
        $this->assertTrue(app(PushRecipients::class)->eligible($registration));
        $binding->update(['status' => 'revoked', 'is_active' => false]);
        $this->assertFalse(app(PushRecipients::class)->eligible($registration));
        $this->withToken($token)->postJson('/api/v1/push-tokens', $body)->assertUnauthorized();
    }

    public function test_delivery_rechecks_recipient_and_deduplicates_without_serializing_plaintext_token(): void
    {
        $user = $this->teacher();
        $registration = $this->registration($user);
        $notification = $this->personal($user);
        $job = new DeliverNotificationPush('personal', $notification->id, $registration->id, $registration->token_hash);
        $this->assertStringNotContainsString($registration->token_ciphertext, serialize($job));
        $this->prepareTransport();
        $job->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        $job->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-oauth-token')
            && $request['message']['data']['notification_id'] === (string) $notification->id
            && $request['message']['token'] === $registration->token_ciphertext);
        $other = $this->personal($user);
        $user->update(['status' => 'suspended']);
        (new DeliverNotificationPush('personal', $other->id, $registration->id, $registration->token_hash))->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        Http::assertSentCount(1);
    }

    public function test_unregistered_token_is_removed_but_invalid_payload_and_other_http_errors_do_not_delete_tokens(): void
    {
        $user = $this->teacher();
        $registration = $this->registration($user);
        $notification = $this->personal($user);
        $job = new DeliverNotificationPush('personal', $notification->id, $registration->id, $registration->token_hash);
        $this->prepareTransport(400, ['error' => ['status' => 'INVALID_ARGUMENT']]);
        $job->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        $this->assertDatabaseHas('push_registrations', ['id' => $registration->id]);
        $this->prepareTransport(404, ['error' => ['details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]]);
        $job->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        $this->assertDatabaseMissing('push_registrations', ['id' => $registration->id]);
    }

    public function test_retry_after_is_honored_and_transport_errors_are_sanitized(): void
    {
        $user = $this->teacher();
        $registration = $this->registration($user);
        $notification = $this->personal($user);
        $job = (new DeliverNotificationPush('personal', $notification->id, $registration->id, $registration->token_hash))->withFakeQueueInteractions();
        $this->prepareTransport(429, ['error' => ['message' => $registration->token_ciphertext]], ['Retry-After' => '180']);
        $job->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        $job->assertReleased(180);
        $this->prepareTransport(503, ['error' => ['message' => $registration->token_ciphertext]]);
        try {
            $job->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
            $this->fail('Transient errors must be retryable.');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString($registration->token_ciphertext, $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_rotated_token_and_read_notification_do_not_receive_an_old_queued_push(): void
    {
        $user = $this->teacher();
        $registration = $this->registration($user);
        $notification = $this->personal($user);
        $this->prepareTransport();
        (new DeliverNotificationPush('personal', $notification->id, $registration->id, str_repeat('0', 64)))->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        $notification->update(['read_at' => now()]);
        (new DeliverNotificationPush('personal', $notification->id, $registration->id, $registration->token_hash))->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        Http::assertNothingSent();
    }

    public function test_admin_campaign_dispatch_is_idempotent_and_does_not_copy_personal_notifications(): void
    {
        Queue::fake();
        config(['notifications.push_enabled' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin', 'status' => 'active']));
        $url = '/api/v1/admin/notification-campaigns';
        $id = $this->postJson($url, ['target_scope' => 'all', 'title' => 'Notice', 'body' => 'One shared row'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->postJson($url.'/'.$id.'/dispatch')->assertOk()->assertJsonPath('data.status', 'dispatched');
        $this->postJson($url.'/'.$id.'/dispatch')->assertOk();
        Queue::assertPushed(DispatchCampaignPush::class, 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('notification_campaigns', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'notification_campaign.dispatched')->where('entity_id', $id)->count());
        $this->putJson($url.'/'.$id, ['target_scope' => 'all', 'title' => 'Edit', 'body' => 'Too late'])->assertConflict();
        $this->postJson($url.'/'.$id.'/cancel')->assertConflict();
        Sanctum::actingAs($this->teacher());
        $this->postJson($url, ['target_scope' => 'all', 'title' => 'Forbidden', 'body' => 'Test'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin', 'status' => 'suspended']));
        $this->getJson($url)->assertForbidden();
    }

    public function test_targeting_visibility_read_markers_and_pagination_do_not_leak_other_recipients(): void
    {
        $teacher = $this->teacher();
        $other = $this->teacher();
        $this->personal($teacher);
        $this->personal($other);
        $shared = $this->campaign();
        $mine = $this->campaign(['target_scope' => 'teacher', 'target_user_id' => $teacher->id]);
        $private = $this->campaign(['target_scope' => 'teacher', 'target_user_id' => $other->id]);
        $this->campaign(['target_scope' => 'students']);
        $this->campaign(['status' => 'scheduled', 'dispatched_at' => null, 'scheduled_at' => now()->addDay()]);
        $this->campaign(['dispatched_at' => now()->subDays(31)]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/v1/teacher/notification-feed?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.unread_count', 3);
        $this->getJson('/api/v1/teacher/notifications')->assertOk()->assertJsonPath('meta.total', 1);
        $path = '/api/v1/teacher/notification-campaigns/'.$shared->id.'/read';
        $this->postJson($path)->assertOk()->assertJsonPath('data.key', 'campaign:'.$shared->id);
        $this->postJson($path)->assertOk();
        $this->assertDatabaseCount('notification_campaign_reads', 1);
        $this->postJson('/api/v1/teacher/notification-campaigns/'.$private->id.'/read')->assertNotFound();
        $this->getJson('/api/v1/teacher/notification-feed?unread_only=1&kind=campaign')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/teacher/notification-feed?kind=campaign')->assertOk()->assertJsonPath('meta.unread_count', 2);
    }

    public function test_due_scheduler_cancel_and_reschedule_are_checked_under_lock(): void
    {
        $due = $this->campaign(['status' => 'scheduled', 'scheduled_at' => now()->subMinute(), 'dispatched_at' => null]);
        $future = $this->campaign(['status' => 'scheduled', 'scheduled_at' => now()->addDay(), 'dispatched_at' => null]);
        $cancelled = $this->campaign(['status' => 'cancelled', 'scheduled_at' => now()->subMinute(), 'dispatched_at' => null]);
        $this->artisan('notifications:dispatch-due')->assertSuccessful();
        $this->assertSame('dispatched', $due->fresh()->status);
        $this->assertSame('scheduled', $future->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->expectException(HttpException::class);
        app(NotificationCampaigns::class)->dispatch($future, true);
    }

    public function test_broadcast_targets_only_matching_registered_accounts_and_individual_jobs_recheck_scope(): void
    {
        Queue::fake();
        $teacher = $this->teacher();
        $other = $this->teacher();
        $registration = $this->registration($teacher);
        $otherRegistration = $this->registration($other);
        $campaign = $this->campaign(['target_scope' => 'teacher', 'target_user_id' => $teacher->id]);
        $this->prepareTransport();
        (new DispatchCampaignPush($campaign->id))->handle();
        Queue::assertPushed(DeliverNotificationPush::class, 1);
        (new DeliverNotificationPush('campaign', $campaign->id, $otherRegistration->id, $otherRegistration->token_hash))->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        Http::assertNothingSent();
        (new DeliverNotificationPush('campaign', $campaign->id, $registration->id, $registration->token_hash))->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        Http::assertSentCount(1);
    }

    public function test_target_validation_and_visibility_retention_do_not_delete_existing_notifications(): void
    {
        $teacher = $this->teacher();
        $notification = $this->personal($teacher);
        DB::table('notifications')->where('id', $notification->id)->update(['created_at' => now()->subDays(31)]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/v1/teacher/notification-feed')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin', 'status' => 'active']));
        $base = ['title' => 'Notice', 'body' => 'Body'];
        $this->postJson('/api/v1/admin/notification-campaigns', $base + ['target_scope' => 'teacher'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/notification-campaigns', $base + ['target_scope' => 'all', 'target_user_id' => $teacher->id])->assertUnprocessable();
        $this->postJson('/api/v1/admin/notification-campaigns', $base + ['target_scope' => 'teacher', 'target_user_id' => $teacher->id, 'scheduled_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
    }

    public function test_pruning_previews_by_default_and_deletes_only_expired_rows_when_explicitly_executed(): void
    {
        $user = $this->teacher();
        $old = $this->personal($user);
        $recent = $this->personal($user);
        DB::table('notifications')->where('id', $old->id)->update(['read_at' => now()->subDays(8)]);
        $expired = $this->campaign(['dispatched_at' => now()->subDays(31)]);
        $draft = $this->campaign(['status' => 'draft', 'dispatched_at' => null]);
        DB::table('notification_campaign_reads')->insert(['campaign_id' => $expired->id, 'user_id' => $user->id, 'read_at' => now()->subDays(20)]);
        $this->artisan('notifications:prune')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('notification_campaigns', 2);
        $this->artisan('notifications:prune', ['--execute' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('notifications', 2);
        $this->artisan('notifications:prune', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseMissing('notifications', ['id' => $old->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recent->id]);
        $this->assertDatabaseMissing('notification_campaigns', ['id' => $expired->id]);
        $this->assertDatabaseHas('notification_campaigns', ['id' => $draft->id]);
        $this->assertDatabaseCount('notification_campaign_reads', 0);
    }

    public function test_unregistered_reply_cannot_delete_a_registration_rotated_while_request_was_in_flight(): void
    {
        $user = $this->teacher();
        $registration = $this->registration($user);
        $notification = $this->personal($user);
        $this->prepareTransport();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function () use ($registration) {
            $registration->update(['token_hash' => hash('sha256', 'rotated-token')]);

            return Http::response(['error' => ['details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404);
        });
        (new DeliverNotificationPush('personal', $notification->id, $registration->id, $registration->token_hash))->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
        $this->assertDatabaseHas('push_registrations', ['id' => $registration->id, 'token_hash' => hash('sha256', 'rotated-token')]);
    }

    public function test_authentication_failure_refreshes_google_token_without_deleting_device_registration(): void
    {
        $user = $this->teacher();
        $registration = $this->registration($user);
        $notification = $this->personal($user);
        $this->prepareTransport(401, ['error' => ['status' => 'UNAUTHENTICATED']]);
        try {
            (new DeliverNotificationPush('personal', $notification->id, $registration->id, $registration->token_hash))->handle(app(FirebaseMessagingService::class), app(PushRecipients::class));
            $this->fail('Authentication refresh must be retried.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('authentication', $error->getMessage());
            $this->assertNull(Cache::get('firebase:fcm:oauth:'.hash('sha256', 'notification-test')));
            $this->assertDatabaseHas('push_registrations', ['id' => $registration->id]);
        }
    }

    public function test_broadcast_is_chunked_and_continues_from_last_registration_instead_of_loading_all_users(): void
    {
        Queue::fake();
        config(['notifications.push_enabled' => true]);
        $user = $this->teacher();
        for ($i = 0; $i < 201; $i++) {
            $this->registration($user);
        }
        $campaign = $this->campaign(['target_scope' => 'teachers']);
        $lastFirstBatchId = PushRegistration::orderBy('id')->limit(200)->pluck('id')->last();
        (new DispatchCampaignPush($campaign->id))->handle();
        Queue::assertPushed(DeliverNotificationPush::class, 200);
        $continuations = Queue::pushed(DispatchCampaignPush::class);
        $this->assertCount(1, $continuations);
        $this->assertSame($lastFirstBatchId, $continuations->first()->afterId);
        $continuations->first()->handle();
        Queue::assertPushed(DeliverNotificationPush::class, 201);
    }
}
