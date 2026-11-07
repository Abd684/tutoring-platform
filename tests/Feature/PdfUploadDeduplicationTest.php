<?php

namespace Tests\Feature;

use App\Models\{Content, ContentAsset, Enrollment, Lesson, Student, Subject, Teacher, TeacherSubject, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PdfUploadDeduplicationTest extends TestCase
{
    use RefreshDatabase;
    private User $teacherUser;
    private Content $content;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['pdf.master_key' => base64_encode(random_bytes(32)), 'filesystems.media_disk' => 'local']);
        $this->teacherUser = User::factory()->create(['role'=>'teacher','status'=>'active']);
        $teacher = Teacher::create(['user_id'=>$this->teacherUser->id,'status'=>'active']);
        $subject = Subject::create(['name'=>'Test','status'=>'active']);
        $course = TeacherSubject::create(['teacher_id'=>$teacher->id,'subject_id'=>$subject->id,'status'=>'active']);
        $unit = Unit::create(['teacher_subject_id'=>$course->id,'title'=>'Test','status'=>'published']);
        $lesson = Lesson::create(['unit_id'=>$unit->id,'title'=>'Test','status'=>'published']);
        $this->content = Content::create(['lesson_id'=>$lesson->id,'type'=>'pdf','title'=>'Notes','price'=>0,'status'=>'published']);
    }

    private function upload(string $name = 'notes.pdf', string $variant = 'demo')
    {
        Sanctum::actingAs($this->teacherUser);
        $source = file_get_contents(base_path('tests/Fixtures/pdf-viewer-demo.pdf'));
        $source = str_replace('PDF viewer demo', 'PDF viewer '.$variant, $source);
        return $this->postJson('/api/v1/teacher/contents/'.$this->content->id.'/asset', [
            'file'=>UploadedFile::fake()->createWithContent($name, $source),
        ]);
    }

    public function test_teacher_retry_with_a_different_filename_reuses_the_same_pdf_and_removes_extra_file(): void
    {
        $first = $this->upload()->assertCreated()->json('data.id');
        $second = $this->upload('renamed.pdf')->assertCreated()->json('data.id');
        $this->assertSame($first, $second);
        $this->assertSame(1, ContentAsset::where('content_id',$this->content->id)->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame('pending', ContentAsset::findOrFail($first)->processing_status);
    }

    public function test_retry_of_a_ready_pdf_preserves_processed_pages_and_does_not_requeue_it(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        ContentAsset::findOrFail($id)->update(['processing_status'=>'ready','total_pages'=>4]);
        $response = $this->upload()->assertCreated();
        $this->assertSame($id,$response->json('data.id'));
        $this->assertSame('ready',$response->json('data.processing_status'));
        $this->assertSame(4,$response->json('data.total_pages'));
    }

    public function test_distinct_pdfs_in_the_same_content_are_not_merged(): void
    {
        $first = $this->upload()->assertCreated()->json('data.id');
        $second = $this->upload('different.pdf','test')->assertCreated()->json('data.id');
        $this->assertNotSame($first,$second);
        $this->assertSame(2,ContentAsset::count());
    }

    public function test_registration_retry_of_same_storage_key_is_idempotent(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        $asset = ContentAsset::findOrFail($id);
        $reply = $this->postJson('/api/v1/teacher/contents/'.$this->content->id.'/asset',[
            'disk'=>$asset->disk,'storage_key'=>$asset->storage_key,'mime_type'=>'application/pdf',
        ])->assertCreated();
        $this->assertSame($id,$reply->json('data.id'));
        $this->assertSame(1,ContentAsset::count());
    }

    public function test_client_supplied_hash_for_another_path_does_not_merge_different_files(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        $asset = ContentAsset::findOrFail($id);
        $path = dirname($asset->storage_key).'/different.pdf';
        Storage::disk('local')->put($path,'%PDF-different');
        $reply = $this->postJson('/api/v1/teacher/contents/'.$this->content->id.'/asset',[
            'disk'=>$asset->disk,'storage_key'=>$path,'mime_type'=>'application/pdf','checksum'=>$asset->checksum,
        ])->assertCreated();
        $this->assertNotSame($id,$reply->json('data.id'));
    }

    public function test_admin_resaving_with_same_pdf_does_not_add_another_asset(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        $admin = User::factory()->create(['role'=>'company_admin','status'=>'active']);
        $this->actingAs($admin,'web');
        $request = \Illuminate\Http\Request::create('/admin/content/'.$this->content->id,'POST',[
            'lesson_id'=>$this->content->lesson_id,'title'=>$this->content->title,'type'=>'pdf',
            'order_no'=>1,'status'=>'published','price'=>0,
        ],[],['file'=>new UploadedFile(base_path('tests/Fixtures/pdf-viewer-demo.pdf'),'again.pdf','application/pdf',null,true)]);
        $request->setUserResolver(fn()=>$admin);
        app()->instance('request',$request);
        app(\App\Http\Controllers\Admin\ContentController::class)->update($request,$this->content);
        $this->assertSame([$id],$this->content->assets()->pluck('id')->all());
        $this->assertCount(1,Storage::disk('local')->allFiles());
    }
}
