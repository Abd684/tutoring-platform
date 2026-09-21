<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\VideoAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoAssetController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = VideoAsset::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $videoAsset = VideoAsset::create($request->validate([
            'content_asset_id' => ['required', 'integer', 'exists:content_assets,id'],
            'hls_manifest_key' => ['required', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'key_reference' => ['nullable', 'string', 'max:255'],
            'watermark_enabled' => ['sometimes', 'boolean'],
        ]));

        return $this->success($videoAsset, 'Video Asset created successfully.', 201);
    }

    public function show(VideoAsset $videoAsset): JsonResponse
    {
        return $this->success($videoAsset);
    }

    public function update(Request $request, VideoAsset $videoAsset): JsonResponse
    {
        $videoAsset->update($request->validate([
            'content_asset_id' => ['sometimes', 'integer', 'exists:content_assets,id'],
            'hls_manifest_key' => ['sometimes', 'string', 'max:255'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'key_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'watermark_enabled' => ['sometimes', 'boolean'],
        ]));

        return $this->success($videoAsset->fresh(), 'Video Asset updated successfully.');
    }

    public function destroy(VideoAsset $videoAsset): JsonResponse
    {
        $videoAsset->delete();

        return $this->success(message: 'Video Asset deleted successfully.');
    }
}

