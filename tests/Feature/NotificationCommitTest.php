<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PushRegistration;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Real commits on disposable SQLite memory prove that rolled-back business
// transactions never leave queue jobs. No RefreshDatabase outer transaction.
class NotificationCommitTest extends TestCase
{
    public function test_queue_is_written_only_after_commit_and_never_after_rollback_even_with_sync_default(): void
    {
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Http::preventStrayRequests();
        config(['notifications.push_enabled' => true, 'notifications.connection' => 'database', 'queue.default' => 'sync']);
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $token = str_repeat('transaction-test-token', 3);
        PushRegistration::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'token_ciphertext' => $token, 'platform' => 'android']);
        DB::beginTransaction();
        Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => 'Rollback', 'body' => 'Message']);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        DB::beginTransaction();
        Notification::create(['user_id' => $user->id, 'type' => 'test', 'title' => 'Commit', 'body' => 'Message']);
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'notifications']);
        Http::assertNothingSent();
    }
}
