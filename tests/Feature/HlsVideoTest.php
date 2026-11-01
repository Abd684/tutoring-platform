<?php

namespace Tests\Feature;

use App\Jobs\ProcessHlsVideo;
use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\DeviceSession;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use App\Services\HlsVideos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HlsVideoTest extends TestCase
{
    use RefreshDatabase;

    private ContentAsset $asset;

    private User $user;

    private User $teacherUser;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'local']);
        Storage::fake('local');
        Queue::fake();
        $this->teacherUser = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'Unit', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'title' => 'Video', 'type' => 'video', 'status' => 'published']);
        $this->user = User::factory()->create();
        $student = Student::create(['user_id' => $this->user->id, 'status' => 'active']);
        $this->enrollment = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollable_type' => 'content', 'enrollable_id' => $content->id, 'status' => 'active']);
        Storage::disk('local')->put('source.mp4', 'placeholder');
        $this->asset = ContentAsset::create(['content_id' => $content->id, 'disk' => 'local', 'storage_key' => 'source.mp4', 'mime_type' => 'video/mp4', 'status' => 'active']);
        Sanctum::actingAs($this->user);
    }

    private function readyFixture(): string
    {
        $directory = 'hls/'.$this->asset->id.'/12345678-1234-1234-1234-123456789abc';
        Storage::disk('local')->put($directory.'/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=900000\n360p/index.m3u8\n");
        Storage::disk('local')->put($directory.'/360p/index.m3u8', "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXT-X-KEY:METHOD=AES-128,URI=\"key.bin\"\n#EXTINF:2.0,\nsegment_000000.ts\n#EXT-X-ENDLIST\n");
        Storage::disk('local')->put($directory.'/360p/key.bin', '1234567890abcdef');
        Storage::disk('local')->put($directory.'/360p/segment_000000.ts', 'segment-data');
        $this->asset->videoAsset()->update(['processing_status' => 'ready', 'hls_directory' => $directory, 'hls_manifest_key' => $directory.'/master.m3u8', 'renditions' => json_encode([['name' => '360p']])]);

        return $directory;
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/content-assets/'.$this->asset->id.'/access-token')->assertOk()->assertJsonPath('data.format', 'hls')->assertJsonPath('data.drm', false)->json('data.playback_url');
    }

    private function uri(string $playlist): string
    {
        return collect(explode("\n", $playlist))->first(fn ($line) => $line !== '' && ! str_starts_with($line, '#'));
    }

    public function test_video_is_queued_and_original_is_never_served_while_pending_or_failed(): void
    {
        Queue::assertPushed(ProcessHlsVideo::class, fn ($job) => $job->assetId === $this->asset->id && $job->connection === 'media' && $job->queue === 'media' && $job->afterCommit);
        $this->postJson('/api/v1/contents/'.$this->asset->content_id.'/playback-token')->assertConflict()->assertJsonPath('code', 'VIDEO_PROCESSING');
        $oldUrl = URL::temporarySignedRoute('student.content.play', now()->addMinutes(5), ['contentAsset' => $this->asset->id, 'student' => $this->enrollment->student_id]);
        $this->getJson($oldUrl)->assertNotFound();
        $this->asset->videoAsset()->update(['processing_status' => 'failed']);
        $this->postJson('/api/v1/content-assets/'.$this->asset->id.'/access-token')->assertConflict()->assertJsonPath('code', 'VIDEO_PROCESSING_FAILED');
        $this->getJson('/api/v1/contents/'.$this->asset->content_id.'/assets')->assertOk()->assertJsonPath('data.0.processing_status', 'failed')->assertJsonMissingPath('data.0.storage_key');
    }

    public function test_every_playlist_and_segment_has_own_signature_and_rechecks_access(): void
    {
        $this->readyFixture();
        $master = $this->token();
        $body = $this->get($master)->assertOk()->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')->getContent();
        $variant = $this->uri($body);
        $variantBody = $this->get($variant)->assertOk()->getContent();
        preg_match('/URI="([^"]+)"/', $variantBody, $matches);
        $keyUrl = $matches[1];
        $this->get($keyUrl)->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(explode('?', $keyUrl)[0])->assertForbidden();
        $segment = $this->uri($variantBody);
        $this->get($segment)->assertOk()->assertHeader('Content-Type', 'video/mp2t')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('source.mp4', $body);
        $this->get(explode('?', $segment)[0])->assertForbidden();
        $this->get(str_replace('segment_000000', 'segment_000001', $segment))->assertForbidden();
        $this->enrollment->update(['access_frozen_at' => now()]);
        $this->get($segment)->assertNotFound();
        $this->get($keyUrl)->assertNotFound();
        $this->enrollment->update(['access_frozen_at' => null]);
        $this->travel(31)->minutes();
        $this->get($segment)->assertForbidden();
        $this->get($keyUrl)->assertForbidden();
    }

    public function test_other_student_and_replaced_generation_cannot_reuse_signed_urls(): void
    {
        $this->readyFixture();
        $url = $this->token();
        $other = User::factory()->create();
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->get($url)->assertForbidden();
        Sanctum::actingAs($this->user);
        $this->asset->update(['storage_key' => 'replacement.mp4']);
        $this->get($url)->assertNotFound();
    }

    public function test_signed_path_traversal_is_rejected_and_original_is_not_in_hls_namespace(): void
    {
        $directory = $this->readyFixture();
        foreach (['../source.mp4', 'source.mp4', '360p/../../source.mp4'] as $file) {
            $url = URL::temporarySignedRoute('student.content.hls', now()->addMinutes(5), ['contentAsset' => $this->asset->id, 'file' => $file, 'student' => $this->enrollment->student_id, 'version' => basename($directory)]);
            $this->get($url)->assertNotFound();
        }
    }

    public function test_only_owner_can_retry_and_pending_jobs_cannot_be_duplicated(): void
    {
        $this->asset->videoAsset()->update(['processing_status' => 'failed']);
        $foreign = User::factory()->create(['role' => 'teacher']);
        Teacher::create(['user_id' => $foreign->id, 'status' => 'active']);
        Sanctum::actingAs($foreign);
        $path = '/api/v1/teacher/content-assets/'.$this->asset->id.'/hls/retry';
        $this->postJson($path)->assertNotFound();
        Sanctum::actingAs($this->teacherUser);
        $this->postJson($path)->assertStatus(202)->assertJsonPath('data.video_asset.processing_status', 'pending');
        $this->postJson($path)->assertConflict();
    }

    public function test_revoked_device_session_blocks_previously_issued_segment_url(): void
    {
        $this->readyFixture();
        Auth::forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['phone' => $this->user->phone, 'password' => 'password', 'device_uuid' => 'hls-device', 'platform' => 'android'])->assertOk();
        $token = $login->json('access_token');
        $this->assertNotEmpty($token);
        Auth::forgetGuards();
        $url = $this->withToken($token)->postJson('/api/v1/content-assets/'.$this->asset->id.'/access-token')->assertOk()->json('data.playback_url');
        Auth::forgetGuards();
        $variant = $this->uri($this->get($url)->assertOk()->getContent());
        Auth::forgetGuards();
        $segment = $this->uri($this->get($variant)->assertOk()->getContent());
        Auth::forgetGuards();
        $this->get($segment)->assertOk();
        DeviceSession::query()->update(['status' => 'revoked', 'revoked_at' => now()]);
        Auth::forgetGuards();
        $this->getJson($segment)->assertUnauthorized();
    }

    public function test_obsolete_source_job_exits_without_touching_replacement(): void
    {
        $hls = app(HlsVideos::class);
        $old = new ProcessHlsVideo($this->asset->id, $hls->fingerprint($this->asset));
        $this->asset->update(['storage_key' => 'replacement.mp4']);
        $old->handle($hls);
        $this->assertSame('pending', $this->asset->videoAsset()->first()->processing_status);
        $this->assertSame($hls->fingerprint($this->asset), $this->asset->videoAsset()->first()->source_fingerprint);
        $this->assertSame([], Storage::disk('local')->allFiles('hls/'.$this->asset->id));
    }

    public function test_real_pipeline_supports_silent_video_at_multiple_qualities(): void
    {
        $source = Storage::disk('local')->path('source.mp4');
        try {
            $check = new Process([config('media.ffmpeg_binary'), '-version']);
            if ($check->run() !== 0) {
                $this->markTestSkipped('FFmpeg required.');
            }
        } catch (ProcessStartFailedException $e) {
            $this->markTestSkipped('FFmpeg required.');
        }
        (new Process([config('media.ffmpeg_binary'), '-nostdin', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:size=1280x720:rate=30', '-t', '1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $source]))->setTimeout(30)->mustRun();
        $hls = app(HlsVideos::class);
        (new ProcessHlsVideo($this->asset->id, $hls->fingerprint($this->asset)))->handle($hls);
        $v = $this->asset->videoAsset()->first();
        $this->assertSame('ready', $v->processing_status);
        $this->assertSame(['360p', '720p'], array_column($v->renditions, 'name'));
        foreach (['360p', '720p'] as $quality) {
            Storage::disk('local')->assertExists($v->hls_directory.'/'.$quality.'/segment_000000.ts');
        }
    }

    public function test_failure_cleans_partial_output_and_never_marks_video_ready(): void
    {
        $hls = app(HlsVideos::class);
        try {
            $hls->process($this->asset, $hls->fingerprint($this->asset));
            $this->fail('Invalid source must fail.');
        } catch (ProcessFailedException|ProcessStartFailedException $error) {
            $this->assertSame('failed', $this->asset->videoAsset()->first()->processing_status);
            $this->assertSame([], Storage::disk('local')->allFiles('hls/'.$this->asset->id));
        }
    }

    public function test_real_ffmpeg_pipeline_generates_playable_hls_and_skips_upscaling(): void
    {
        foreach ([config('media.ffmpeg_binary'), config('media.ffprobe_binary')] as $binary) {
            try {
                if ((new Process([$binary, '-version']))->run() !== 0) {
                    $this->markTestSkipped('FFmpeg/ffprobe required for real integration test.');
                }
            } catch (\Throwable $e) {
                $this->markTestSkipped('FFmpeg/ffprobe required for real integration test.');
            }
        }
        $source = Storage::disk('local')->path('source.mp4');
        (new Process([config('media.ffmpeg_binary'), '-nostdin', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=30', '-f', 'lavfi', '-i', 'sine=frequency=1000', '-t', '2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $source]))->setTimeout(30)->mustRun();
        Sanctum::actingAs($this->teacherUser);
        $upload = $this->post('/api/v1/teacher/contents/'.$this->asset->content_id.'/asset', [
            'file' => new UploadedFile($source, 'lesson.mp4', 'video/mp4', null, true),
        ])->assertCreated()->assertJsonPath('data.video_asset.processing_status', 'pending');
        $this->asset = ContentAsset::findOrFail($upload->json('data.id'));
        Queue::assertPushed(ProcessHlsVideo::class, fn ($queued) => $queued->assetId === $this->asset->id);
        Sanctum::actingAs($this->user);
        $hls = app(HlsVideos::class);
        $job = new ProcessHlsVideo($this->asset->id, $hls->fingerprint($this->asset));
        $job->handle($hls);
        $v = $this->asset->videoAsset()->first();
        $this->assertSame('ready', $v->processing_status);
        $this->assertSame(['360p'], array_column($v->renditions, 'name'));
        $this->assertSame(2, $v->duration_seconds);
        $this->assertStringContainsString('#EXT-X-ENDLIST', Storage::disk('local')->get($v->hls_directory.'/360p/index.m3u8'));
        $playlist = Storage::disk('local')->get($v->hls_directory.'/360p/index.m3u8');
        $this->assertStringContainsString('METHOD=AES-128', $playlist);
        $this->assertSame(16, Storage::disk('local')->size($v->hls_directory.'/360p/key.bin'));
        (new Process([config('media.ffprobe_binary'), '-v', 'error', '-protocol_whitelist', 'file,crypto,data', '-allowed_extensions', 'ALL', '-show_entries', 'stream=codec_name', '-of', 'json', Storage::disk('local')->path($v->hls_directory.'/360p/index.m3u8')]))->setTimeout(20)->mustRun();
        $files = Storage::disk('local')->allFiles($v->hls_directory);
        $job->handle($hls);
        $this->assertSame($files, Storage::disk('local')->allFiles($v->hls_directory));
        $variant = $this->uri($this->get($this->token())->assertOk()->getContent());
        $this->get($this->uri($this->get($variant)->assertOk()->getContent()))->assertOk();
    }
}
