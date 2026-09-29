<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentContentAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_with_active_content_enrollment_can_view_file_inline(): void
    {
        Storage::fake('local');
        [$studentUser, $student] = $this->student();
        [$asset, $content, $teacher] = $this->asset();
        Enrollment::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'enrollable_type' => Content::class,
            'enrollable_id' => $content->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);
        Sanctum::actingAs($studentUser);

        $token = $this->postJson('/api/v1/contents/'.$content->id.'/playback-token')
            ->assertOk()
            ->assertJsonStructure(['data' => ['playback_url', 'expires_at']]);

        $this->get($token->json('data.playback_url'))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename='.basename($asset->storage_key));
    }

    public function test_unsubscribed_or_expired_student_cannot_view_file(): void
    {
        Storage::fake('local');
        [$studentUser, $student] = $this->student();
        [$asset, $content, $teacher] = $this->asset();
        Sanctum::actingAs($studentUser);

        $this->postJson('/api/v1/contents/'.$content->id.'/playback-token')->assertNotFound();

        Enrollment::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'enrollable_type' => Content::class,
            'enrollable_id' => $content->id,
            'status' => 'active',
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/contents/'.$content->id.'/playback-token')->assertNotFound();
    }

    private function student(): array
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $user->id, 'status' => 'active']);

        return [$user, $student];
    }

    private function asset(): array
    {
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Math', 'status' => 'active']);
        $teacherSubject = TeacherSubject::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'price' => 10,
            'status' => 'active',
        ]);
        $unit = Unit::create(['teacher_subject_id' => $teacherSubject->id, 'title' => 'Unit']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson']);
        $content = Content::create(['lesson_id' => $lesson->id, 'type' => 'pdf', 'title' => 'Content']);
        $path = "teachers/{$teacher->id}/contents/{$content->id}/lesson.pdf";
        Storage::disk('local')->put($path, 'private lesson');
        $asset = ContentAsset::create([
            'content_id' => $content->id,
            'disk' => 'local',
            'storage_key' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 14,
            'status' => 'active',
        ]);

        return [$asset, $content, $teacher];
    }
}
