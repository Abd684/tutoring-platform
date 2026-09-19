<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContentAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentAssetController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ContentAsset::query()->with(['content:id,lesson_id,type,title,status']);

        $query->when($request->filled('content_id'), fn ($q) => $q->where('content_id', $request->integer('content_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('encryption_status'), fn ($q) => $q->where('encryption_status', $request->string('encryption_status')));

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $asset = ContentAsset::create($request->validate([
            'content_id' => ['required', 'integer', 'exists:contents,id'],
            'disk' => ['required', 'string', 'max:100'],
            'storage_key' => ['required', 'string', 'max:2048'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'file_size' => ['nullable', 'integer', 'min:0'],
            'checksum' => ['nullable', 'string', 'max:255'],
            'encryption_type' => ['nullable', 'string', 'max:100'],
            'encryption_status' => ['sometimes', Rule::in(['pending', 'processing', 'done', 'failed'])],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ]));

        return $this->success($asset, 'Content asset created successfully.', 201);
    }

    public function show(ContentAsset $contentAsset): JsonResponse
    {
        return $this->success($contentAsset->load(['content:id,lesson_id,type,title,status', 'videoAsset', 'pdfAsset']));
    }

    public function update(Request $request, ContentAsset $contentAsset): JsonResponse
    {
        $contentAsset->update($request->validate([
            'content_id' => ['sometimes', 'integer', 'exists:contents,id'],
            'disk' => ['sometimes', 'required', 'string', 'max:100'],
            'storage_key' => ['sometimes', 'required', 'string', 'max:2048'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'file_size' => ['nullable', 'integer', 'min:0'],
            'checksum' => ['nullable', 'string', 'max:255'],
            'encryption_type' => ['nullable', 'string', 'max:100'],
            'encryption_status' => ['sometimes', Rule::in(['pending', 'processing', 'done', 'failed'])],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ]));

        return $this->success($contentAsset->fresh(), 'Content asset updated successfully.');
    }

    public function destroy(ContentAsset $contentAsset): JsonResponse
    {
        $contentAsset->delete();

        return $this->success(message: 'Content asset deleted successfully.');
    }
}
