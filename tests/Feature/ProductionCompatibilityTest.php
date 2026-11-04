<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $u = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'password_hash' => Hash::make('Password123!')]);
        $t = Teacher::create(['user_id' => $u->id, 'status' => 'active']);
        $c = TeacherSubject::create(['teacher_id' => $t->id, 'subject_id' => Subject::create(['name' => 'Compatibility', 'status' => 'active'])->id, 'status' => 'active']);
        $g = Group::create(['teacher_subject_id' => $c->id, 'name' => 'Existing group', 'capacity' => 3, 'status' => 'active']);
        $s = Student::create(['user_id' => User::factory()->create(['role' => 'student', 'status' => 'active', 'password_hash' => Hash::make('Password123!')])->id, 'status' => 'active']);
        $e = Enrollment::create(['teacher_id' => $t->id, 'student_id' => $s->id, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $c->id, 'price' => 10, 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth()]);

        return compact('u', 't', 'c', 'g', 's', 'e');
    }

    public function test_existing_teacher_and_student_email_login_still_work_while_phone_login_remains_available(): void
    {
        $f = $this->fixture();
        foreach (['email', 'phone'] as $field) {
            $this->postJson('/api/v1/teacher/login', [$field => $f['u']->$field, 'password' => 'Password123!'])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
            $this->postJson('/api/v1/auth/login', [$field => $f['s']->user->$field, 'password' => 'Password123!', 'device_uuid' => 'compat-device', 'platform' => 'android'])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
        }
    }

    public function test_legacy_group_membership_routes_preserve_enrollment_ids_and_ownership(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['u']);
        $url = '/api/v1/teacher/groups/'.$f['g']->id.'/enrollments';
        $this->postJson($url, ['enrollment_id' => $f['e']->id])->assertCreated()->assertJsonPath('data.enrollment_id', $f['e']->id)->assertJsonPath('data.student_id', $f['s']->id);
        $this->deleteJson($url.'/'.$f['e']->id)->assertOk()->assertJsonPath('data.status', 'removed');
        $other = $this->fixture();
        Sanctum::actingAs($other['u']);
        $this->postJson($url, ['enrollment_id' => $f['e']->id])->assertNotFound();
        $this->assertDatabaseHas('group_students', ['group_id' => $f['g']->id, 'student_id' => $f['s']->id, 'status' => 'removed']);
    }

    public function test_old_session_input_and_output_and_explicit_new_contract_both_work(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['u']);
        $base = ['starts_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String(), 'ends_at' => now()->addDays(3)->setTime(11, 0)->toIso8601String()];
        $id = $this->postJson('/api/v1/teacher/sessions', $base + ['type' => 'GROUP', 'group_id' => $f['g']->id, 'enrollment_id' => null])->assertCreated()->assertJsonPath('data.type', 'GROUP')->assertJsonPath('data.group_id', $f['g']->id)->json('data.id');
        $this->getJson('/api/v1/teacher/sessions')->assertOk()->assertJsonPath('data.0.type', 'GROUP');
        $this->getJson('/api/v1/teacher/sessions', ['X-Liqaa-Teaching-Contract' => '2'])->assertOk()->assertJsonPath('data.0.type', 'group');
        $this->patchJson('/api/v1/teacher/sessions/'.$id, ['type' => 'INDIVIDUAL', 'group_id' => null, 'enrollment_id' => $f['e']->id])->assertOk()->assertJsonPath('data.type', 'INDIVIDUAL')->assertJsonPath('data.enrollment_id', $f['e']->id)->assertJsonPath('data.capacity', 1);
        $this->assertDatabaseCount('individuals', 1);
    }

    public function test_failed_legacy_session_request_does_not_leave_an_orphan_individual(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs($f['u']);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'INDIVIDUAL', 'enrollment_id' => $f['e']->id, 'starts_at' => 'not-a-date', 'ends_at' => 'not-a-date'])->assertUnprocessable();
        $this->assertDatabaseCount('individuals',0);
        $this->assertDatabaseCount('legacy_teaching_links',0);
    }
}
