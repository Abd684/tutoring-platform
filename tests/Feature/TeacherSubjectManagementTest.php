<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherSubjectManagementTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/teacher/subjects';

    private function teacher(string $status = 'active', string $userStatus = 'active'): User
    {
        $user = User::factory()->create(['role' => 'teacher', 'status' => $userStatus]);
        Teacher::create(['user_id' => $user->id, 'status' => $status]);

        return $user;
    }

    private function payload(): array
    {
        return ['subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id,
            'price' => 25, 'group_enabled' => true, 'max_group_size' => 10];
    }

    public function test_active_teacher_can_manage_own_subject_without_supplying_teacher_id(): void
    {
        $user = $this->teacher();
        Sanctum::actingAs($user);
        $payload = $this->payload();
        $id = $this->postJson(self::URL, $payload)->assertCreated()
            ->assertJsonPath('data.teacher_id', $user->teacher->id)
            ->assertJsonPath('data.group_enabled', true)->json('data.id');

        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::URL.'/'.$id)->assertOk();
        $this->patchJson(self::URL.'/'.$id, ['price' => 30, 'group_enabled' => false])
            ->assertOk()->assertJsonPath('data.price', '30.00')->assertJsonPath('data.max_group_size', null);
        $this->postJson(self::URL, $payload)->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->deleteJson(self::URL.'/'.$id)->assertOk();
        $this->assertDatabaseMissing('teacher_subjects', ['id' => $id]);
    }

    public function test_teacher_id_cannot_be_spoofed_on_create_or_update_and_lists_are_scoped(): void
    {
        $owner = $this->teacher();
        $other = $this->teacher();
        $payload = $this->payload();
        TeacherSubject::create([...$payload, 'teacher_id' => $other->teacher->id]);
        Sanctum::actingAs($owner);

        $id = $this->postJson(self::URL, [...$payload, 'teacher_id' => $other->teacher->id])
            ->assertCreated()->assertJsonPath('data.teacher_id', $owner->teacher->id)->json('data.id');
        $this->getJson(self::URL.'?teacher_id='.$other->teacher->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->patchJson(self::URL.'/'.$id, ['teacher_id' => $other->teacher->id, 'price' => 40])
            ->assertOk()->assertJsonPath('data.teacher_id', $owner->teacher->id);
    }

    public function test_other_teachers_cannot_read_change_or_delete_a_subject(): void
    {
        $owner = $this->teacher();
        $course = TeacherSubject::create([...$this->payload(), 'teacher_id' => $owner->teacher->id]);
        Sanctum::actingAs($this->teacher());

        $this->getJson(self::URL.'/'.$course->id)->assertNotFound();
        $this->patchJson(self::URL.'/'.$course->id, ['price' => 1])->assertNotFound();
        $this->deleteJson(self::URL.'/'.$course->id)->assertNotFound();
        $this->assertDatabaseHas('teacher_subjects', ['id' => $course->id, 'price' => 25]);
    }

    public function test_inactive_profiles_or_accounts_cannot_manage_subjects(): void
    {
        foreach ([['pending_review', 'active'], ['suspended', 'active'], ['active', 'suspended']] as [$status, $userStatus]) {
            $user = $this->teacher($status, $userStatus);
            $payload = $this->payload();
            $course = TeacherSubject::create([...$payload, 'teacher_id' => $user->teacher->id]);
            Sanctum::actingAs($user);
            $this->postJson(self::URL, $payload)->assertForbidden();
            $this->getJson(self::URL)->assertForbidden();
            $this->getJson(self::URL.'/'.$course->id)->assertNotFound();
            $this->patchJson(self::URL.'/'.$course->id, ['price' => 1])->assertNotFound();
            $this->deleteJson(self::URL.'/'.$course->id)->assertNotFound();
        }
    }

    public function test_missing_profiles_wrong_roles_and_guests_are_rejected(): void
    {
        $payload = $this->payload();
        $this->postJson(self::URL, $payload)->assertUnauthorized();
        foreach (['teacher', 'student', 'company_admin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson(self::URL, $payload)->assertForbidden();
            $this->getJson(self::URL)->assertForbidden();
        }
    }

    public function test_pending_teacher_can_create_after_administrator_activation(): void
    {
        $teacher = $this->teacher('pending_review');
        $payload = $this->payload();
        Sanctum::actingAs($teacher);
        $this->postJson(self::URL, $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin']));
        $this->patchJson('/api/v1/teacher/'.$teacher->teacher->id.'/activate')->assertOk();

        Sanctum::actingAs($teacher->fresh());
        $this->postJson(self::URL, $payload)->assertCreated();
    }

    public function test_validation_and_optional_group_fields(): void
    {
        Sanctum::actingAs($this->teacher());
        $this->postJson(self::URL, [])->assertUnprocessable()->assertJsonValidationErrors(['subject_id', 'price']);
        $payload = $this->payload();
        $this->postJson(self::URL, [...$payload, 'price' => -1, 'group_enabled' => 'invalid', 'max_group_size' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['price', 'group_enabled', 'max_group_size']);
        $this->postJson(self::URL, ['subject_id' => $payload['subject_id'], 'price' => 0])
            ->assertCreated()->assertJsonPath('data.group_enabled', false)->assertJsonPath('data.max_group_size', null);
    }

    public function test_other_teacher_lists_allow_active_teachers_and_reject_pending_profiles(): void
    {
        foreach (['active', 'pending_review', 'suspended'] as $status) {
            Sanctum::actingAs($this->teacher($status));
            foreach (['availability', 'sessions', 'content-assets', 'subscription-plans'] as $path) {
                $response = $this->getJson('/api/v1/teacher/'.$path);
                $status === 'active' ? $response->assertOk() : $response->assertForbidden();
            }
        }
    }
}
