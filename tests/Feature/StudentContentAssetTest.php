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

    public function test_student_cannot_retrieve_original_pdf_through_legacy_playback_route(): void
    {
        Storage::fake('local');
        [$studentUser, $student] = $this->student();
        [$asset, $content, $teacher] = $this->asset();
        Enrollment::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'enrollable_type' => (new Content)->getMorphClass(),
            'enrollable_id' => $content->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);
        Sanctum::actingAs($studentUser);

        $this->postJson('/api/v1/contents/'.$content->id.'/playback-token')->assertStatus(410);
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
            'enrollable_type' => (new Content)->getMorphClass(),
            'enrollable_id' => $content->id,
            'status' => 'active',
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/contents/'.$content->id.'/playback-token')->assertNotFound();
    }

    public function test_ready_pdf_is_served_only_with_owner_session_token_and_can_be_revoked(): void
    {
        Storage::fake('local'); [$user, $student] = $this->student(); [$asset, $content, $teacher] = $this->asset();
        $asset->update(['processing_status'=>'ready','total_pages'=>1]);
        $canvas=imagecreatetruecolor(400,600); ob_start(); imagejpeg($canvas); $page=ob_get_clean(); imagedestroy($canvas);
        Storage::disk('local')->put('pdf-pages/'.$asset->id.'/v1/1.jpg',$page);
        Enrollment::create(['student_id'=>$student->id,'teacher_id'=>$teacher->id,'enrollable_type'=>(new Content)->getMorphClass(),'enrollable_id'=>$content->id,'status'=>'active','starts_at'=>now()->subDay(),'expires_at'=>now()->addDay()]);
        Sanctum::actingAs($user);
        $open=$this->postJson('/api/v1/pdf/'.$asset->id.'/view')->assertOk();
        $reference=$open->json('data.session_reference'); $token=$open->json('data.session_token');
        $this->getJson('/api/v1/pdf/sessions/'.$reference.'/pages/1')->assertForbidden();
        $this->withHeader('X-PDF-Session',$token)->getJson('/api/v1/pdf/sessions/'.$reference.'/pages/1')->assertOk()->assertJsonPath('data.mime_type','image/jpeg');
        $this->deleteJson('/api/v1/pdf/sessions/'.$reference)->assertOk();
        $this->withHeader('X-PDF-Session',$token)->getJson('/api/v1/pdf/sessions/'.$reference.'/pages/1')->assertForbidden();
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
        $unit = Unit::create(['teacher_subject_id' => $teacherSubject->id, 'title' => 'Unit', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'type' => 'pdf', 'title' => 'Content', 'status' => 'published']);
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
