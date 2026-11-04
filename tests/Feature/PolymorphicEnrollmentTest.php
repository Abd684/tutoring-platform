<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use App\Services\ContentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PolymorphicEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private function setupStudent(): Student
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $user->id, 'status' => 'active']);
        Sanctum::actingAs($user);

        return $student;
    }

    private function curriculum(): array
    {
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Math', 'status' => 'active']);
        $item = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'price' => 100, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $item->id, 'title' => 'Algebra', 'price' => 25, 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Equations', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'title' => 'PDF', 'type' => 'pdf', 'price' => 5, 'status' => 'published']);

        return [$item, $unit, $content, $teacher];
    }

    public function test_steps_1_to_8_purchase_duplicates_coverage_and_payload(): void
    {
        $student = $this->setupStudent();
        [$subject, $coveredUnit, $subjectContent] = $this->curriculum();
        [$otherSubject, $unit, $unitContent] = $this->curriculum();
        [$thirdSubject, $thirdUnit, $content] = $this->curriculum();
        foreach ([['teacher-subjects', $subject, 'subject'], ['units', $unit, 'unit'], ['contents', $content, 'content']] as [$path, $model, $type]) {
            $result = $this->postJson('/api/v1/'.$path.'/'.$model->id.'/enroll')
                ->assertCreated()->assertJsonPath('status', true)->assertJsonPath('data.type', $type)
                ->assertJsonPath('data.item.id', $model->id)->assertJsonPath('data.status', 'pending')
                ->assertJsonPath('data.item.subject_name', 'Math');
            $this->assertDatabaseHas('enrollments', ['id' => $result->json('data.id'), 'student_id' => $student->id, 'enrollable_type' => $model->getMorphClass(), 'enrollable_id' => $model->id]);
            $this->postJson('/api/v1/'.$path.'/'.$model->id.'/enroll')->assertConflict()
                ->assertJsonPath('code', 'ALREADY_ENROLLED')->assertJsonPath('enrollment_id', $result->json('data.id'));
        }
        Enrollment::query()->update(['status' => 'active']);
        foreach ([['units', $coveredUnit, 'SUBJECT'], ['contents', $subjectContent, 'SUBJECT'], ['contents', $unitContent, 'UNIT']] as [$path, $model, $scope]) {
            $this->postJson('/api/v1/'.$path.'/'.$model->id.'/enroll')->assertConflict()->assertJsonPath('code', 'ALREADY_COVERED_BY_'.$scope);
        }
        $this->assertDatabaseCount('enrollments', 3);
        $response = $this->getJson('/api/v1/student/enrollments')->assertOk()
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 1);
        $this->assertSame(['content', 'unit', 'subject'], array_column($response->json('data'), 'type'));
        $this->assertSame(['content', 'teacher_subject', 'unit'], DB::table('enrollments')->distinct()->orderBy('enrollable_type')->pluck('enrollable_type')->all());
    }

    public function test_step_9_each_active_scope_opens_protected_pdf_viewing_and_denies_outside_scope(): void
    {
        Storage::fake('local');
        $student = $this->setupStudent();
        [$subject, $unit, $content, $teacher] = $this->curriculum();
        [, , $outside] = $this->curriculum();
        $assets = [];
        foreach ([$content, $outside] as $file) {
            Storage::disk('local')->put('test/'.$file->id.'.pdf', 'private');
            $asset = ContentAsset::create(['content_id' => $file->id, 'disk' => 'local', 'storage_key' => 'test/'.$file->id.'.pdf', 'mime_type' => 'application/pdf', 'status' => 'active']);
            $asset->update(['processing_status' => 'ready', 'total_pages' => 1]); $assets[$file->id] = $asset;
        }
        foreach ([$content, $unit, $subject] as $model) {
            $enrollment = $model->enrollments()->create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'status' => 'pending']);
            $this->postJson('/api/v1/pdf/'.$assets[$content->id]->id.'/view')->assertNotFound();
            $enrollment->update(['status' => 'active']);
            $response = $this->postJson('/api/v1/pdf/'.$assets[$content->id]->id.'/view')->assertOk();
            $this->assertNotEmpty($response->json('data.session_token'));
            $this->postJson('/api/v1/pdf/'.$assets[$outside->id]->id.'/view')->assertNotFound();
            $enrollment->delete();
        }
    }

    public function test_group_membership_is_not_derived_from_online_enrollment_alias(): void
    {
        $student = $this->setupStudent();
        [$subject, $unit, $content, $teacher] = $this->curriculum();
        $group = Group::create(['teacher_subject_id' => $subject->id, 'name' => 'Group', 'capacity' => 10, 'status' => 'active']);
        Sanctum::actingAs($teacher->user);
        $this->postJson('/api/v1/teacher/groups/'.$group->id.'/students', ['student_id' => $student->id])->assertCreated();
        $this->assertDatabaseHas('group_students', ['group_id' => $group->id, 'student_id' => $student->id]);
    }

    public function test_enrollment_rejects_types_outside_its_domain(): void
    {
        $student = $this->setupStudent();
        [, , , $teacher] = $this->curriculum();
        foreach (['user', User::class, '', null] as $type) {
            try {
                Enrollment::create([
                    'student_id' => $student->id,
                    'teacher_id' => $teacher->id,
                    'enrollable_type' => $type,
                    'enrollable_id' => 1,
                    'status' => 'pending',
                ]);
                $this->fail('Invalid enrollment type was accepted.');
            } catch (\LogicException $exception) {
                $this->assertStringStartsWith('Invalid enrollable type:', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_legacy_enrollment_class_names_are_normalized_to_allowed_aliases(): void
    {
        $student = $this->setupStudent();
        [$course, $unit, $content, $teacher] = $this->curriculum();
        foreach ([$course, $unit, $content] as $item) {
            $enrollment = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $teacher->id,
                'enrollable_type' => $item::class, 'enrollable_id' => $item->id, 'status' => 'pending']);
            $this->assertSame($item->getMorphClass(), $enrollment->fresh()->getRawOriginal('enrollable_type'));
        }
    }

    public function test_migration_normalizes_existing_class_names(): void
    {
        $student = $this->setupStudent();
        [$subject, $unit, $content, $teacher] = $this->curriculum();
        foreach ([$subject, $unit, $content] as $model) {
            DB::table('enrollments')->insert(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollable_type' => get_class($model), 'enrollable_id' => $model->id, 'status' => 'active']);
        }
        $migration = require database_path('migrations/2026_10_15_122315_normalize_enrollment_morph_types.php');
        $migration->up();
        $migration->up();
        $this->assertSame(['content', 'teacher_subject', 'unit'], DB::table('enrollments')->orderBy('enrollable_type')->pluck('enrollable_type')->all());
        $this->getJson('/api/v1/student/enrollments')->assertOk()->assertJsonPath('data.0.type', 'content')->assertJsonPath('data.1.type', 'unit')->assertJsonPath('data.2.type', 'subject');
    }

    public function test_pending_parent_does_not_cover_children_but_duplicate_pending_is_rejected(): void
    {
        $this->setupStudent();
        [$subject, $unit, $content] = $this->curriculum();
        foreach ([['teacher-subjects', $subject], ['units', $unit], ['contents', $content]] as [$path, $item]) {
            $this->postJson('/api/v1/'.$path.'/'.$item->id.'/enroll')->assertCreated();
            $this->postJson('/api/v1/'.$path.'/'.$item->id.'/enroll')->assertConflict()->assertJsonPath('code', 'ALREADY_ENROLLED');
        }
    }

    public function test_content_access_time_boundaries_and_sibling_units(): void
    {
        $student = $this->setupStudent();
        [$course, $unit, $content, $teacher] = $this->curriculum();
        $otherUnit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'B', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $otherUnit->id, 'title' => 'B', 'status' => 'published']);
        $other = Content::create(['lesson_id' => $lesson->id, 'type' => 'pdf', 'title' => 'B', 'status' => 'published']);
        $enrollment = $unit->enrollments()->create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'status' => 'active']);
        $access = app(ContentAccess::class);
        $this->assertTrue($access->allows($student, $content));
        $this->assertFalse($access->allows($student, $other));
        $this->freezeTime();
        $enrollment->update(['expires_at' => now()]);
        $this->assertFalse($access->allows($student, $content));
        $enrollment->update(['expires_at' => null, 'starts_at' => now()->addSecond()]);
        $this->assertFalse($access->allows($student, $content));
        $enrollment->update(['starts_at' => now()]);
        $this->assertTrue($access->allows($student, $content));
    }
}
