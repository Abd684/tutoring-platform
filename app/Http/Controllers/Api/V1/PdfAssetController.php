<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\PdfAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PdfAssetController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = PdfAsset::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $pdfAsset = PdfAsset::create($request->validate([
            'content_asset_id' => ['required', 'integer', 'exists:content_assets,id'],
            'page_count' => ['nullable', 'integer', 'min:1'],
            'watermark_enabled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($pdfAsset, 'Pdf Asset created successfully.', 201);
    }

    public function show(PdfAsset $pdfAsset): JsonResponse
    {
        return $this->success($pdfAsset);
    }

    public function update(Request $request, PdfAsset $pdfAsset): JsonResponse
    {
        $pdfAsset->update($request->validate([
            'content_asset_id' => ['sometimes', 'integer', 'exists:content_assets,id'],
            'page_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'watermark_enabled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($pdfAsset->fresh(), 'Pdf Asset updated successfully.');
    }

    public function destroy(PdfAsset $pdfAsset): JsonResponse
    {
        $pdfAsset->delete();

        return $this->success(message: 'Pdf Asset deleted successfully.');
    }
}

