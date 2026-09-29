<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherContentAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_upload_list_and_delete_a_file_for_owned_content(): void
    {
        Storage::fake('local');
        [$user, $content] = $this->teacherAndContent('owner@example.com', 'pdf');
        Sanctum::actingAs($user);

        $upload = $this->post('/api/v1/teacher/contents/'.$content->id.'/asset', [
            'file' => UploadedFile::fake()->create('lesson.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('message', 'File uploaded successfully.');

        $assetId = $upload->json('data.id');
        $storageKey = $upload->json('data.storage_key');
        Storage::disk('local')->assertExists($storageKey);

        $this->getJson('/api/v1/teacher/content-assets')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson('/api/v1/teacher/content-assets/'.$assetId)->assertOk();
        Storage::disk('local')->assertMissing($storageKey);
        $this->assertDatabaseMissing('content_assets', ['id' => $assetId]);
    }

    public function test_teacher_cannot_access_another_teachers_files_or_upload_to_their_content(): void
    {
        Storage::fake('local');
        [$owner, $content] = $this->teacherAndContent('owner@example.com', 'pdf');
        [$other] = $this->teacherAndContent('other@example.com', 'pdf');

        Sanctum::actingAs($owner);
        $assetId = $this->post('/api/v1/teacher/contents/'.$content->id.'/asset', [
            'file' => UploadedFile::fake()->create('private.pdf', 50, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($other);

        $this->getJson('/api/v1/teacher/content-assets')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/teacher/content-assets/'.$assetId)->assertNotFound();
        $this->deleteJson('/api/v1/teacher/content-assets/'.$assetId)->assertNotFound();
        $this->post('/api/v1/teacher/contents/'.$content->id.'/asset', [
            'file' => UploadedFile::fake()->create('attack.pdf', 50, 'application/pdf'),
        ])->assertNotFound();

        $this->assertDatabaseHas('content_assets', ['id' => $assetId]);
    }

    public function test_upload_requires_an_authenticated_teacher_and_public_asset_crud_is_disabled(): void
    {
        [, $content] = $this->teacherAndContent('owner@example.com', 'pdf');

        $this->withHeader('Accept', 'application/json')
            ->post('/api/v1/teacher/contents/'.$content->id.'/asset', [
                'file' => UploadedFile::fake()->create('lesson.pdf', 10, 'application/pdf'),
            ])->assertUnauthorized();

        $this->getJson('/api/v1/content-assets')->assertNotFound();
    }

    public function test_teacher_can_create_content_and_register_asset_metadata_exactly_as_documented(): void
    {
        [$user, $ownedContent] = $this->teacherAndContent('documented@example.com', 'video');
        Sanctum::actingAs($user);

        $createdContent = $this->postJson('/api/v1/teacher/contents', [
            'lesson_id' => $ownedContent->lesson_id,
            'type' => 'video',
            'title' => 'Lesson introduction',
            'description' => 'Introduction video',
            'order_no' => 1,
            'status' => 'draft',
            'published_at' => null,
        ])->assertCreated()
            ->assertJsonPath('message', 'Content created successfully.');

        $contentId = $createdContent->json('data.id');

        $this->postJson("/api/v1/teacher/contents/{$contentId}/asset", [
            'disk' => 'public',
            'storage_key' => 'contents/videos/video-1.mp4',
            'mime_type' => 'video/mp4',
            'file_size' => 5242880,
            'checksum' => 'sha256-value',
            'encryption_type' => 'AES-256',
            'encryption_status' => 'pending',
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('message', 'Content asset created successfully.')
            ->assertJsonPath('data.content_id', $contentId);
    }

    private function teacherAndContent(string $email, string $type): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Subject '.$teacher->id, 'status' => 'active']);
        $teacherSubject = TeacherSubject::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'price' => 10,
            'status' => 'active',
        ]);
        $unit = Unit::create(['teacher_subject_id' => $teacherSubject->id, 'title' => 'Unit']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson']);
        $content = Content::create(['lesson_id' => $lesson->id, 'type' => $type, 'title' => 'Content']);

        return [$user, $content];
    }
}
