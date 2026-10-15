<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MostRequestedCoursesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/teacher-subjects/most-requested';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
    }

    private function course(string $name = 'Mathematics'): TeacherSubject
    {
        $teacher = Teacher::create([
            'user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id,
            'status' => 'active',
        ]);

        return TeacherSubject::create([
            'teacher_id' => $teacher->id,
            'subject_id' => Subject::create(['name' => $name, 'status' => 'active'])->id,
            'price' => 100,
            'status' => 'active',
        ]);
    }

    private function enroll(TeacherSubject $course, array $attributes = []): Enrollment
    {
        $studentId = $attributes['student_id'] ?? Student::create([
            'user_id' => User::factory()->create(['role' => 'student'])->id,
            'status' => 'active',
        ])->id;

        return Enrollment::create(array_merge([
            'student_id' => $studentId,
            'teacher_id' => $course->teacher_id,
            'enrollable_type' => TeacherSubject::class,
            'enrollable_id' => $course->id,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
        ], $attributes));
    }

    public function test_ranks_unique_students_and_counts_only_confirmed_enrollments_in_the_window(): void
    {
        $lessPopular = $this->course();
        $popular = $this->course('Physics');
        $first = $this->enroll($popular);
        $this->enroll($popular, ['student_id' => $first->student_id, 'status' => 'expired']);
        $this->enroll($popular, ['status' => 'expired', 'starts_at' => now()->subYears(3)]);
        $this->enroll($popular, ['starts_at' => now()]);
        $this->enroll($lessPopular);
        foreach (['pending', 'cancelled'] as $status) {
            $this->enroll($lessPopular, ['status' => $status]);
        }
        $this->enroll($lessPopular, ['starts_at' => now()->subYears(3)->subSecond()]);
        $this->enroll($lessPopular, ['starts_at' => now()->addSecond()]);
        $this->enroll($lessPopular, ['starts_at' => null]);
        // A unit enrollment with the same numeric ID must not count as a course enrollment.
        $this->enroll($lessPopular, ['enrollable_type' => \App\Models\Unit::class]);

        $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $popular->id)
            ->assertJsonPath('data.0.name', 'Physics')
            ->assertJsonPath('data.0.teacher.id', $popular->teacher_id)
            ->assertJsonPath('data.0.students_count', 3)
            ->assertJsonPath('data.1.students_count', 1)
            ->assertJsonPath('meta.limit', 10);
    }

    public function test_hides_unavailable_courses_and_courses_without_qualifying_enrollments(): void
    {
        $this->course();
        $inactive = $this->course();
        $this->enroll($inactive);
        $inactive->update(['status' => 'inactive']);
        $hiddenSubject = $this->course();
        $this->enroll($hiddenSubject);
        $hiddenSubject->subject->update(['status' => 'inactive']);
        $suspended = $this->course();
        $this->enroll($suspended);
        $suspended->teacher->update(['status' => 'suspended']);
        $inactiveUser = $this->course();
        $this->enroll($inactiveUser);
        $inactiveUser->teacher->user->update(['status' => 'suspended']);

        $this->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_limit_and_ties_are_deterministic(): void
    {
        $first = $this->course();
        $second = $this->course();
        $this->enroll($first);
        $this->enroll($second);

        $this->getJson(self::URL.'?limit=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('meta.limit', 1);
    }

    public function test_invalid_limits_are_rejected(): void
    {
        foreach (['0', '101', '-1', 'abc', '1.5'] as $limit) {
            $this->getJson(self::URL.'?limit='.$limit)->assertUnprocessable()
                ->assertJsonValidationErrors('limit');
        }
    }
}
