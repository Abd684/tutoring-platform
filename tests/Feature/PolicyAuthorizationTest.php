<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PolicyAuthorizationTest extends TestCase
{
    private function account(string $role, int $id): User
    {
        $user = (new User)->forceFill(['id' => $id, 'role' => $role, 'status' => 'active']);
        $user->setRelation('teacher', $role === 'teacher'
            ? (new Teacher)->forceFill(['id' => $id + 100, 'status' => 'active']) : null);
        $user->setRelation('student', $role === 'student'
            ? (new Student)->forceFill(['id' => $id + 200, 'status' => 'active']) : null);

        return $user;
    }

    public function test_every_model_has_a_discovered_policy_and_generic_crud_requires_active_admin(): void
    {
        $admin = $this->account('company_admin', 1);
        $student = $this->account('student', 2);
        $teacher = $this->account('teacher', 3);

        foreach (glob(app_path('Models/*.php')) as $path) {
            $class = 'App\\Models\\'.basename($path, '.php');
            $record = new $class;
            $this->assertNotNull(Gate::getPolicyFor($record), $class);
            if (in_array($class, [Session::class, TeacherAvailability::class], true)) {
                continue;
            }
            foreach (['create', 'update', 'delete', 'viewAny'] as $ability) {
                $target = in_array($ability, ['create', 'viewAny'], true) ? $class : $record;
                $this->assertTrue(Gate::forUser($admin)->allows($ability, $target), "$class $ability");
                $this->assertFalse(Gate::forUser($student)->allows($ability, $target), "$class $ability student");
                $this->assertFalse(Gate::forUser($teacher)->allows($ability, $target), "$class $ability teacher");
                $admin->status = 'suspended';
                $this->assertFalse(Gate::forUser($admin)->allows($ability, $target), "$class suspended admin");
                $admin->status = 'active';
            }
            $this->assertFalse(Gate::forUser($admin)->allows('restore', $record));
            $this->assertFalse(Gate::forUser($admin)->allows('forceDelete', $record));
            $this->assertFalse(Gate::allows('create', $class));
        }
    }

    public function test_content_management_follows_the_teacher_through_the_curriculum(): void
    {
        $owner = $this->account('teacher', 1);
        $other = $this->account('teacher', 2);
        $subject = (new TeacherSubject)->forceFill(['teacher_id' => $owner->teacher->id]);
        $unit = (new Unit)->setRelation('teacherSubject', $subject);
        $lesson = (new Lesson)->setRelation('unit', $unit);
        $content = (new Content)->setRelation('lesson', $lesson);

        $this->assertTrue(Gate::forUser($owner)->allows('upload', $content));
        $denial = Gate::forUser($other)->inspect('upload', $content);
        $this->assertFalse($denial->allowed());
        $this->assertSame(404, $denial->status());
        $owner->teacher->status = 'suspended';
        $this->assertFalse(Gate::forUser($owner)->allows('upload', $content));
    }

    public function test_private_financial_records_are_readable_only_by_their_owner_or_admin(): void
    {
        $owner = $this->account('student', 1);
        $other = $this->account('student', 2);
        $payment = (new Payment)->forceFill(['payer_id' => $owner->id]);
        $student = (new Student)->forceFill(['user_id' => $owner->id]);
        $enrollment = (new Enrollment)->setRelation('student', $student);

        foreach ([$payment, $enrollment] as $record) {
            $this->assertTrue(Gate::forUser($owner)->allows('view', $record));
            $this->assertSame(404, Gate::forUser($other)->inspect('view', $record)->status());
            $this->assertFalse(Gate::forUser($owner)->allows('update', $record));
            $owner->status = 'suspended';
            $this->assertFalse(Gate::forUser($owner)->allows('view', $record));
            $owner->status = 'active';
        }
    }

    public function test_session_requires_correct_role_active_profile_and_ownership(): void
    {
        $student = $this->account('student', 5);
        $this->assertTrue(Gate::forUser($student)->allows('book', Session::class));
        $student->status = 'suspended';
        $this->assertFalse(Gate::forUser($student)->allows('book', Session::class));

        $owner = $this->account('teacher', 1);
        $session = (new Session)->forceFill(['teacher_id' => $owner->teacher->id]);
        $this->assertTrue(Gate::forUser($owner)->allows('recordAttendance', $session));
        $this->assertFalse(Gate::forUser($this->account('teacher', 2))->allows('update', $session));
        $owner->role = 'student';
        $this->assertFalse(Gate::forUser($owner)->allows('create', Session::class));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $session));
        $this->assertFalse(Gate::forUser($this->account('student', 4))->allows('delete', new Session));
    }
}
