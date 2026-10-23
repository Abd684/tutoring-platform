<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Student;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Services\ContentAccess;
use App\Services\ProtectedS3Media;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentContentAssetController extends ApiController
{
    public function playbackToken(Request $request, int $id): JsonResponse
    {
        $this->authorize('accessStudent', Content::class);

        $student = $request->user()->student;
        abort_if($student === null || $student->status !== 'active', 403, 'Active student profile required.');

        $content = Content::query()->with('lesson.unit.teacherSubject')->findOrFail($id);
        $unit = $content?->lesson?->unit;
        $teacherSubject = $unit?->teacherSubject;

        abort_unless($content !== null && $unit !== null && $teacherSubject !== null, 404);
        $this->authorize('access', $content);

        $contentAsset = $content->assets()->where('status', 'active')
            ->when($content->type === 'video', fn ($q) => $q->where('mime_type', 'like', 'video/%'))
            ->latest('id')->firstOrFail();

        return $this->assetToken($contentAsset, $student);
    }

    public function assets(Request $request, Content $content): JsonResponse
    {
        $this->authorize('accessStudent', Content::class);
        $this->authorize('access', $content);

        return $this->success($content->assets()->where('status', 'active')->with('videoAsset')->get()->map(fn ($asset) => [
            'id' => $asset->id, 'mime_type' => $asset->mime_type, 'file_size' => $asset->file_size,
            'duration_seconds' => $asset->videoAsset?->duration_seconds,
            'token_endpoint' => url('/api/v1/content-assets/'.$asset->id.'/access-token'),
        ]));
    }

    public function accessToken(Request $request, ContentAsset $contentAsset): JsonResponse
    {
        $this->authorize('accessStudent', Content::class);
        $student = $request->user()->student;
        abort_unless($contentAsset->status === 'active', 404);
        $this->authorize('access', $contentAsset->content);

        return $this->assetToken($contentAsset, $student);
    }

    private function assetToken(ContentAsset $contentAsset, Student $student): JsonResponse
    {
        abort_if($contentAsset->disk === 'public', 404, 'Protected files require private storage.');
        abort_unless(Storage::disk($contentAsset->disk)->exists($contentAsset->storage_key), 404);

        $expiresAt = now()->addMinutes(5);
        $playbackUrl = URL::temporarySignedRoute('student.content.play', $expiresAt, [
            'contentAsset' => $contentAsset->id,
            'student' => $student->id,
        ]);

        return $this->success([
            'playback_url' => $playbackUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 'Playback token created successfully.');
    }

    public function play(Request $request, ContentAsset $contentAsset): StreamedResponse|BinaryFileResponse
    {
        $this->authorize('accessStudent', Content::class);

        $student = $request->user()->student;
        abort_if($student === null || $student->status !== 'active', 403, 'Active student profile required.');
        abort_unless((int) $request->query('student') === (int) $student->id, 403);

        $contentAsset->loadMissing('content.lesson.unit.teacherSubject');
        $content = $contentAsset->content;
        $unit = $content?->lesson?->unit;
        $teacherSubject = $unit?->teacherSubject;

        abort_unless($content !== null && $unit !== null && $teacherSubject !== null, 404);
        $this->authorize('access', $content);

        $disk = Storage::disk($contentAsset->disk);
        abort_if($contentAsset->disk === 'public', 404);
        abort_unless($contentAsset->status === 'active' && $disk->exists($contentAsset->storage_key), 404);

        $headers = ['Content-Type' => $contentAsset->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'"];
        if (config('filesystems.disks.'.$contentAsset->disk.'.driver') === 'local') {
            return response()->file($disk->path($contentAsset->storage_key), $headers)
                ->setContentDisposition('inline', basename($contentAsset->storage_key));
        }
        if ($disk instanceof AwsS3V3Adapter) {
            return app(ProtectedS3Media::class)->response($request, $contentAsset, $disk, $headers);
        }

        return $disk->response(
            $contentAsset->storage_key,
            null,
            $headers,
            'inline',
        );
    }

    private function hasActiveEnrollment(Student $student, Content $content, Unit $unit, TeacherSubject $teacherSubject): bool
    {
        return app(ContentAccess::class)->allows($student, $content);
    }
}
