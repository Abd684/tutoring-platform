<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\ContentCompletion;
use App\Models\Enrollment;
use App\Models\Governortate;
use App\Models\Lesson;
use App\Models\Region;
use App\Models\School;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use App\Models\VideoAsset;
use App\Services\ContentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function student(): array
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active', 'password_hash' => Hash::make('Password123!')]);

        return [$user, Student::create(['user_id' => $user->id, 'status' => 'active'])];
    }

    private function course(): array
    {
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Math', 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'price' => 50, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'Unit', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'title' => 'Video', 'type' => 'video', 'status' => 'published', 'learning_objectives' => ['Solve an equation']]);

        return [$course, $content, $teacher];
    }

    private function enroll(Student $student, TeacherSubject $course, array $extra = []): Enrollment
    {
        return Enrollment::create(array_merge(['student_id' => $student->id, 'teacher_id' => $course->teacher_id,
            'enrollable_type' => 'teacher_subject', 'enrollable_id' => $course->id, 'status' => 'active',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addDay()], $extra));
    }

    public function test_profile_updates_are_scoped_and_validate_school_email_and_privileged_fields(): void
    {
        [$user] = $this->student();
        [$other] = $this->student();
        $g = Governortate::create(['name' => 'Test']);
        $region = Region::create(['name' => 'Region', 'governortate_id' => $g->id]);
        $region2 = Region::create(['name' => 'Other', 'governortate_id' => $g->id]);
        $school = School::create(['name' => 'School', 'region_id' => $region->id]);
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/profile', ['name' => 'Updated', 'phone' => '099', 'grade' => '12',
            'region_id' => $region->id, 'school_id' => $school->id, 'role' => 'company_admin', 'status' => 'suspended'])
            ->assertOk()->assertJsonPath('user.name', 'Updated')->assertJsonPath('student.grade', '12')->assertJsonPath('user.role', 'student');
        $this->patchJson('/api/v1/profile', ['region_id' => $region2->id])->assertUnprocessable();
        $this->patchJson('/api/v1/profile', ['email' => $other->email])->assertUnprocessable();
        $this->assertNotSame('Updated', $other->fresh()->name);
    }

    public function test_avatar_upload_replacement_and_delete_are_reflected_in_me(): void
    {
        Storage::fake('public');
        [$user] = $this->student();
        Sanctum::actingAs($user);
        // A real PNG fixture without requiring the GD extension.
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6ZQAAAABJRU5ErkJggg==');
        $upload = fn () => UploadedFile::fake()->createWithContent('avatar.png', $bytes);
        $url = $this->postJson('/api/v1/profile/avatar', ['avatar' => $upload()])->assertOk()->json('data.avatar_url');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.avatar_url', $url);
        $old = $user->fresh()->avatar_path;
        $this->postJson('/api/v1/profile/avatar', ['avatar' => $upload()])->assertOk();
        Storage::disk('public')->assertMissing($old);
        $this->postJson('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])->assertUnprocessable();
        $this->deleteJson('/api/v1/profile/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->getJson('/api/v1/me')->assertJsonPath('user.avatar_url', null);
    }

    public function test_progress_is_persisted_idempotent_and_ignores_unpublished_contents(): void
    {
        [$user, $student] = $this->student();
        [$course, $content] = $this->course();
        $enrollment = $this->enroll($student, $course);
        $second = $content->replicate();
        $second->save();
        $draft = $content->replicate();
        $draft->status = 'draft';
        $draft->save();
        Sanctum::actingAs($user);
        $url = '/api/v1/student/contents/'.$content->id.'/complete';
        $this->postJson($url)->assertOk()->assertJsonPath('data.progress_percentage', 50)->assertJsonPath('data.completed_content_ids', [$content->id]);
        $this->postJson($url)->assertOk()->assertJsonPath('data.completed_contents', 1);
        $this->assertDatabaseCount('content_completions', 1);
        $this->postJson('/api/v1/student/contents/'.$draft->id.'/complete')->assertNotFound();
        $this->getJson('/api/v1/student/teacher-subjects/'.$course->id.'/progress')->assertOk()->assertJsonPath('data.total_contents', 2);
        $this->getJson('/api/v1/student/teacher-subjects/'.$course->id)->assertOk()->assertJsonPath('data.units_count', 1)->assertJsonPath('data.lessons_count', 1)->assertJsonPath('data.contents_count', 2);
        $enrollment->update(['expires_at' => now()->subMinute()]);
        $this->postJson($url)->assertNotFound();
        $this->getJson('/api/v1/student/teacher-subjects/'.$course->id.'/progress')->assertNotFound();
    }

    public function test_attachment_tokens_are_scoped_expiring_and_recheck_access(): void
    {
        Storage::fake('local');
        [$user, $student] = $this->student();
        [$course, $content] = $this->course();
        $enrollment = $this->enroll($student, $course);
        Storage::disk('local')->put('video.mp4', 'video');
        Storage::disk('local')->put('notes.pdf', 'pdf');
        $video = ContentAsset::create(['content_id' => $content->id, 'disk' => 'local', 'storage_key' => 'video.mp4', 'mime_type' => 'video/mp4', 'status' => 'active']);
        VideoAsset::create(['content_asset_id' => $video->id, 'duration_seconds' => 121]);
        $pdf = ContentAsset::create(['content_id' => $content->id, 'disk' => 'local', 'storage_key' => 'notes.pdf', 'mime_type' => 'application/pdf', 'status' => 'active']);
        Sanctum::actingAs($user);
        $assets = $this->getJson('/api/v1/contents/'.$content->id.'/assets')->assertOk()->json('data');
        $this->assertCount(2, $assets);
        $this->assertArrayNotHasKey('storage_key', $assets[0]);
        $videoUrl = $this->postJson('/api/v1/contents/'.$content->id.'/playback-token')->assertOk()->json('data.playback_url');
        $this->assertStringContainsString('/'.$video->id.'/play', $videoUrl);
        $pdfUrl = $this->postJson('/api/v1/content-assets/'.$pdf->id.'/access-token')->assertOk()->json('data.playback_url');
        $this->get($pdfUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->withHeader('Range', 'bytes=0-1')->get($pdfUrl)->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-1/3');
        $this->withHeader('Range', '');
        $this->getJson('/api/v1/lessons/'.$content->lesson_id.'/contents')->assertJsonPath('data.0.duration_seconds', 121)->assertJsonPath('data.0.learning_objectives', ['Solve an equation']);
        [$other] = $this->student();
        Sanctum::actingAs($other);
        $this->get($pdfUrl)->assertForbidden();
        Sanctum::actingAs($user);
        $enrollment->update(['status' => 'cancelled']);
        $this->get($pdfUrl)->assertNotFound();
        $enrollment->update(['status' => 'active']);
        $this->travel(6)->minutes();
        $this->get($pdfUrl)->assertForbidden();
    }

    public function test_teacher_statistics_use_real_counts_and_nullable_experience(): void
    {
        [$course, $content, $teacher] = $this->course();
        [, $student] = $this->student();
        $this->enroll($student, $course);
        $this->enroll($student, $course);
        ContentCompletion::create(['student_id' => $student->id, 'content_id' => $content->id, 'completed_at' => now()]);
        $this->getJson('/api/v1/teachers/'.$teacher->id)->assertOk()->assertJsonPath('data.students_count', 1)
            ->assertJsonPath('data.experience_years', null)->assertJsonPath('data.completion_rate', 100);
        Sanctum::actingAs($teacher->user);
        $this->patchJson('/api/v1/teacher/profile', ['experience_years' => 7])->assertOk();
        $this->patchJson('/api/v1/teacher/contents/'.$content->id, ['learning_objectives' => ['Updated objective']])->assertOk();
        $this->getJson('/api/v1/teachers/'.$teacher->id)->assertJsonPath('data.experience_years', 7);
    }

    public function test_session_completion_and_history_are_scoped_and_attendance_is_separate(): void
    {
        [$user, $student] = $this->student();
        [$course, , $teacher] = $this->course();
        $enrollment = $this->enroll($student, $course);
        $session = Session::create(['teacher_id' => $teacher->id, 'type' => 'INDIVIDUAL', 'enrollment_id' => $enrollment->id,
            'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(), 'status' => 'scheduled']);
        Sanctum::actingAs($teacher->user);
        $this->postJson('/api/v1/teacher/sessions/'.$session->id.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson('/api/v1/teacher/sessions/'.$session->id.'/complete')->assertOk();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/student/bookings')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student/bookings?scope=history')->assertJsonPath('data.0.status', 'completed');
        $this->getJson('/api/v1/student/sessions/'.$session->id)->assertOk();
        [$other] = $this->student();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/student/sessions/'.$session->id)->assertNotFound();
        $this->assertDatabaseCount('attendance', 0);
    }

    public function test_trial_command_is_idempotent_and_uses_normal_enrollment(): void
    {
        [$user, $student] = $this->student();
        [$course, $content] = $this->course();
        $this->artisan('student:grant-trial', ['student_user' => $user->email, 'teacher_subject_id' => $course->id])->assertSuccessful();
        $this->artisan('student:grant-trial', ['student_user' => (string) $user->id, 'teacher_subject_id' => $course->id])->assertSuccessful();
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertTrue(app(ContentAccess::class)->allows($student, $content));
    }

    public function test_upgrade_can_resume_with_existing_profile_columns_without_losing_data(): void
    {
        [$user] = $this->student();
        $user->forceFill(['avatar_path' => 'avatars/existing.png'])->save();
        $migration = require database_path('migrations/2026_10_15_000001_complete_student_features.php');
        $migration->up();
        $this->assertSame('avatars/existing.png', $user->fresh()->avatar_path);
        $this->assertSame('active', $user->fresh()->status);
    }
}
