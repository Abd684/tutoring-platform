<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Teacher;
use App\Services\HlsVideos;
use App\Services\ListSearch;
use App\Services\VideoMetadata;
use App\Services\PdfEncryption;
use App\Services\PdfAssetRegistration;
use Illuminate\Support\Facades\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TeacherContentAssetController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewOwnAny', ContentAsset::class);

        $teacher = $this->teacher($request);

        $assets = ContentAsset::query()
            ->with(['content:id,lesson_id,type,title,status', 'videoAsset'])
            ->whereHas('content.lesson.unit.teacherSubject', fn ($query) => $query->where('teacher_id', $teacher->id))
            ->when($request->filled('content_id'), fn ($query) => $query->where('content_id', $request->integer('content_id')))
            ->orderByDesc('id')
            ->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'assets'))->paginate($this->perPage($request));

        return $this->paginated($assets);
    }

    public function store(Request $request, Content $content): JsonResponse
    {
        $teacher = $this->teacher($request);
        $this->authorize('upload', $content);
        abort_if(config('filesystems.media_disk', 'local') === 'public', 422, 'Protected media requires a private disk.');

        if (! $request->hasFile('file')) {
            $key = $request->input('storage_key');
            if (! is_string($key) || ! str_starts_with($key, "teachers/{$teacher->id}/contents/{$content->id}/")
                || str_contains($key, '..') || str_contains($key, '\\')) {
                throw ValidationException::withMessages(['storage_key' => ['File must be in the owned content directory.']]);
            }
            $attributes = $request->validate([
                    'disk' => ['required', Rule::in(array_values(array_diff(array_keys(config('filesystems.disks')), ['public'])))],
                    'storage_key' => ['required', 'string', 'max:2048'],
                    'mime_type' => ['required', Rule::in(['application/pdf', 'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'])],
                    'file_size' => ['nullable', 'integer', 'min:0'],
                    'checksum' => ['nullable', 'string', 'max:255'],
                    'encryption_type' => ['nullable', 'string', 'max:100'],
                    'encryption_status' => ['sometimes', Rule::in(['pending', 'processing', 'done', 'failed'])],
                    'status' => ['sometimes', Rule::in(['active', 'archived'])],
                ]);
            $asset = $attributes['mime_type'] === 'application/pdf'
                ? app(PdfAssetRegistration::class)->register($content, $attributes)
                : ContentAsset::create(['content_id' => $content->id, ...$attributes]);

            app(VideoMetadata::class)->probe($asset);

            return $this->success($asset->load('videoAsset'), 'Content asset created successfully.', 201);
        }

        if (! in_array($content->type, ['pdf', 'video'], true)) {
            throw ValidationException::withMessages([
                'content' => ['Files can only be uploaded to PDF or video content.'],
            ]);
        }

        $validated = $request->validate(['file' => ['required', 'file', 'max:'.($content->type === 'pdf' ? 25600 : 512000)]]);

        $file = $validated['file'];
        $allowedMimeTypes = $content->type === 'pdf'
            ? ['application/pdf']
            : ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'];

        if (! in_array($file->getMimeType(), $allowedMimeTypes, true) || ($content->type === 'pdf' && ! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-'))) {
            throw ValidationException::withMessages([
                'file' => ["The uploaded file is not a valid {$content->type} file."],
            ]);
        }

        $disk = config('filesystems.media_disk', 'local');
        $directory = "teachers/{$teacher->id}/contents/{$content->id}";
        $storageKey = $file->store($directory, $disk);
        if ($storageKey === false) {
            return $this->businessError('The file could not be stored.', 500);
        }

        if ($content->type === 'pdf') {
            $work = storage_path('app/private/pdf-upload/'.\Illuminate\Support\Str::uuid());
            File::ensureDirectoryExists(dirname($work));
            try {
                app(PdfEncryption::class)->encrypt($file->getRealPath(), $work);
                Storage::disk($disk)->put($storageKey, fopen($work, 'rb'));
            } catch (Throwable $exception) {
                Storage::disk($disk)->delete($storageKey);
                throw $exception;
            } finally { @unlink($work); }
        }

        try {
            $attributes = [
                'disk' => $disk,
                'storage_key' => $storageKey,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'encryption_type' => $content->type === 'pdf' ? 'AES-256-GCM-ENVELOPE' : null,
                'encryption_status' => $content->type === 'pdf' ? 'done' : 'pending',
                'status' => 'active',
                'processing_status' => $content->type === 'pdf' ? 'pending' : 'ready',
            ];
            $asset = $content->type === 'pdf'
                ? app(PdfAssetRegistration::class)->register($content, $attributes, true)
                : ContentAsset::create(['content_id' => $content->id, ...$attributes]);
            if ($asset->disk !== $disk || $asset->storage_key !== $storageKey) {
                Storage::disk($disk)->delete($storageKey);
            }
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storageKey);
            throw $exception;
        }

        app(VideoMetadata::class)->probe($asset, $file->getRealPath() ?: null);

        return $this->success($asset->load('videoAsset'), 'File uploaded successfully.', 201);
    }

    public function show(Request $request, ContentAsset $contentAsset): JsonResponse
    {
        $this->authorize('manage', $contentAsset);

        return $this->success($contentAsset->load(['content:id,lesson_id,type,title,status', 'videoAsset']));
    }

    public function destroy(Request $request, ContentAsset $contentAsset): JsonResponse
    {
        $this->authorize('manage', $contentAsset);

        Storage::disk($contentAsset->disk)->delete($contentAsset->storage_key);
        if ($contentAsset->mime_type === 'application/pdf') Storage::disk($contentAsset->disk)->deleteDirectory('pdf-pages/'.$contentAsset->id);
        $contentAsset->delete();

        return $this->success(message: 'File deleted successfully.');
    }

    public function retry(ContentAsset $contentAsset): JsonResponse
    {
        $this->authorize('manage', $contentAsset);
        abort_unless($contentAsset->status === 'active' && str_starts_with($contentAsset->mime_type ?? '', 'video/'), 422);

        return DB::transaction(function () use ($contentAsset) {
            $asset = ContentAsset::whereKey($contentAsset->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($asset->videoAsset?->processing_status, ['pending', 'processing', 'ready'], true), 409, 'الفيديو جاهز أو توجد معالجة قائمة.');
            app(HlsVideos::class)->enqueue($asset);

            return $this->success($asset->fresh()->load('videoAsset'), 'تمت جدولة إعادة المعالجة.', 202);
        });
    }

    private function teacher(Request $request): Teacher
    {
        $teacher = $request->user()->teacher;
        abort_if($teacher === null, 403, 'Teacher profile not found.');

        return $teacher;
    }
}
