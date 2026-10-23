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
        Enrollment::where('student_id', $student->id)->update(['status' => 'active']);
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

    public function test_step_9_each_active_scope_grants_signed_playback_and_denies_outside_scope(): void
    {
        Storage::fake('local');
        $student = $this->setupStudent();
        [$subject, $unit, $content, $teacher] = $this->curriculum();
        [, , $outside] = $this->curriculum();
        foreach ([$content, $outside] as $file) {
            Storage::disk('local')->put('test/'.$file->id.'.pdf', 'private');
            ContentAsset::create(['content_id' => $file->id, 'disk' => 'local', 'storage_key' => 'test/'.$file->id.'.pdf', 'mime_type' => 'application/pdf', 'status' => 'active']);
        }
        foreach ([$content, $unit, $subject] as $model) {
            $enrollment = $model->enrollments()->create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'status' => 'pending']);
            $this->postJson('/api/v1/contents/'.$content->id.'/playback-token')->assertNotFound();
            $enrollment->update(['status' => 'active']);
            $this->freezeTime();
            $response = $this->postJson('/api/v1/contents/'.$content->id.'/playback-token')->assertOk();
            $url = $response->json('data.playback_url');
            $this->assertTrue(URL::hasValidSignature(Request::create($url)));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame(now()->addMinutes(5)->timestamp, (int) $query['expires']);
            $this->get($url)->assertOk();
            $this->postJson('/api/v1/contents/'.$outside->id.'/playback-token')->assertNotFound();
            $enrollment->delete();
        }
    }

    public function test_step_10_group_requires_matching_active_subject_alias(): void
    {
        $student = $this->setupStudent();
        [$subject, $unit, $content, $teacher] = $this->curriculum();
        $other = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => Subject::create(['name' => 'Other'])->id, 'price' => 10, 'status' => 'active']);
        $group = Group::create(['teacher_subject_id' => $subject->id, 'name' => 'Group', 'capacity' => 10, 'status' => 'active']);
        Sanctum::actingAs($teacher->user);
        foreach ([$subject, $unit, $content, $other] as $model) {
            $enrollment = $model->enrollments()->create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'status' => 'active']);
            $response = $this->postJson('/api/v1/teacher/groups/'.$group->id.'/enrollments', ['enrollment_id' => $enrollment->id]);
            if ($model === $subject) {
                $response->assertCreated()->assertJsonPath('message', 'Enrollment added to group successfully.');
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors('enrollment_id');
            }
        }
        $this->assertDatabaseCount('group_enrollments', 1);
    }

    public function test_migration_normalizes_existing_class_names(): void
    {
        $student = $this->setupStudent();
        [$subject, $unit, $content, $teacher] = $this->curriculum();
        foreach ([$subject, $unit, $content] as $model) {
            Enrollment::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollable_type' => get_class($model), 'enrollable_id' => $model->id, 'status' => 'active']);
        }
        $migration = require database_path('migrations/2026_10_15_122315_normalize_enrollment_morph_types.php');
        $migration->up();
        $migration->up();
        $this->assertSame(['content', 'teacher_subject', 'unit'], DB::table('enrollments')->orderBy('enrollable_type')->pluck('enrollable_type')->all());
        $this->getJson('/api/v1/student/enrollments')->assertOk()->assertJsonPath('data.0.type', 'content')->assertJsonPath('data.1.type', 'unit')->assertJsonPath('data.2.type', 'subject');
    }
}
