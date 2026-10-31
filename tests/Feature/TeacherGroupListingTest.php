<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherGroupListingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/teacher/groups';

    private function teacher(string $status = 'active', string $userStatus = 'active'): User
    {
        $user = User::factory()->create(['role' => 'teacher', 'status' => $userStatus]);
        Teacher::create(['user_id' => $user->id, 'status' => $status]);

        return $user;
    }

    private function course(User $user): TeacherSubject
    {
        return TeacherSubject::create([
            'teacher_id' => $user->teacher->id,
            'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id,
            'price' => 25, 'group_enabled' => true, 'max_group_size' => 10, 'status' => 'active',
        ]);
    }

    public function test_created_group_is_listed_and_other_teachers_groups_are_excluded(): void
    {
        $owner = $this->teacher();
        $course = $this->course($owner);
        $foreign = $this->course($this->teacher());
        Group::create(['teacher_subject_id' => $foreign->id, 'name' => 'Other', 'capacity' => 5, 'status' => 'active']);
        Sanctum::actingAs($owner);
        $id = $this->postJson(self::URL, ['teacher_subject_id' => $course->id, 'name' => 'My group', 'capacity' => 5])
            ->assertCreated()->json('data.id');

        $this->getJson(self::URL.'?teacher_id='.$foreign->teacher_id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.active_enrollments_count', 0)
            ->assertJsonPath('data.0.teacher_subject.subject.name', 'Math');
        $this->getJson(self::URL.'?teacher_subject_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filters_and_pagination_preserve_ownership_and_include_all_statuses(): void
    {
        $owner = $this->teacher();
        $course = $this->course($owner);
        foreach (['forming', 'active', 'closed'] as $status) {
            Group::create(['teacher_subject_id' => $course->id, 'name' => 'Group '.$status, 'capacity' => 5, 'status' => $status]);
        }
        Sanctum::actingAs($owner);
        $this->getJson(self::URL.'?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson(self::URL.'?status=forming&search=forming&teacher_subject_id='.$course->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'forming');
    }

    public function test_guests_wrong_roles_and_inactive_or_missing_profiles_cannot_list_groups(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        foreach ([['pending_review', 'active'], ['suspended', 'active'], ['active', 'suspended']] as [$profile, $account]) {
            Sanctum::actingAs($this->teacher($profile, $account));
            $this->getJson(self::URL)->assertForbidden();
        }
        foreach (['teacher', 'student', 'company_admin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'status' => 'active']));
            $this->getJson(self::URL)->assertForbidden();
        }
    }
}
