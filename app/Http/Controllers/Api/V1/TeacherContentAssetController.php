<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TeacherContentAssetController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $teacher = $this->teacher($request);

        $assets = ContentAsset::query()
            ->with('content:id,lesson_id,type,title,status')
            ->whereHas('content.lesson.unit.teacherSubject', fn ($query) => $query->where('teacher_id', $teacher->id))
            ->when($request->filled('content_id'), fn ($query) => $query->where('content_id', $request->integer('content_id')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($assets);
    }

    public function store(Request $request, Content $content): JsonResponse
    {
        $teacher = $this->teacher($request);
        $this->ensureOwnsContent($content, $teacher);

        if (! $request->hasFile('file')) {
            $asset = ContentAsset::create([
                'content_id' => $content->id,
                ...$request->validate([
                    'disk' => ['required', 'string', 'max:100'],
                    'storage_key' => ['required', 'string', 'max:2048'],
                    'mime_type' => ['nullable', 'string', 'max:255'],
                    'file_size' => ['nullable', 'integer', 'min:0'],
                    'checksum' => ['nullable', 'string', 'max:255'],
                    'encryption_type' => ['nullable', 'string', 'max:100'],
                    'encryption_status' => ['sometimes', Rule::in(['pending', 'processing', 'done', 'failed'])],
                    'status' => ['sometimes', Rule::in(['active', 'archived'])],
                ]),
            ]);

            return $this->success($asset, 'Content asset created successfully.', 201);
        }

        if (! in_array($content->type, ['pdf', 'video'], true)) {
            throw ValidationException::withMessages([
                'content' => ['Files can only be uploaded to PDF or video content.'],
            ]);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:512000'],
        ]);

        $file = $validated['file'];
        $allowedMimeTypes = $content->type === 'pdf'
            ? ['application/pdf']
            : ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'];

        if (! in_array($file->getMimeType(), $allowedMimeTypes, true)) {
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

        try {
            $asset = ContentAsset::create([
                'content_id' => $content->id,
                'disk' => $disk,
                'storage_key' => $storageKey,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'encryption_type' => null,
                'encryption_status' => 'pending',
                'status' => 'active',
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storageKey);
            throw $exception;
        }

        return $this->success($asset, 'File uploaded successfully.', 201);
    }

    public function show(Request $request, ContentAsset $contentAsset): JsonResponse
    {
        $this->ensureOwnsAsset($contentAsset, $this->teacher($request));

        return $this->success($contentAsset->load('content:id,lesson_id,type,title,status'));
    }

    public function destroy(Request $request, ContentAsset $contentAsset): JsonResponse
    {
        $this->ensureOwnsAsset($contentAsset, $this->teacher($request));

        Storage::disk($contentAsset->disk)->delete($contentAsset->storage_key);
        $contentAsset->delete();

        return $this->success(message: 'File deleted successfully.');
    }

    private function teacher(Request $request): Teacher
    {
        $teacher = $request->user()->teacher;
        abort_if($teacher === null, 403, 'Teacher profile not found.');

        return $teacher;
    }

    private function ensureOwnsContent(Content $content, Teacher $teacher): void
    {
        $ownsContent = Content::query()
            ->whereKey($content->id)
            ->whereHas('lesson.unit.teacherSubject', fn ($query) => $query->where('teacher_id', $teacher->id))
            ->exists();

        abort_unless($ownsContent, 404);
    }

    private function ensureOwnsAsset(ContentAsset $contentAsset, Teacher $teacher): void
    {
        $ownsAsset = ContentAsset::query()
            ->whereKey($contentAsset->id)
            ->whereHas('content.lesson.unit.teacherSubject', fn ($query) => $query->where('teacher_id', $teacher->id))
            ->exists();

        abort_unless($ownsAsset, 404);
    }
}
