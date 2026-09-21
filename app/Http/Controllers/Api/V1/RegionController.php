<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return $this->paginated(Region::orderBy('name')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $region = Region::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'governortate_id' => ['required', 'integer', 'exists:governortates,id'],
        ]));

        return $this->success($region, 'Region created successfully.', 201);
    }

    public function show(Region $region): JsonResponse
    {
        return $this->success($region);
    }

    public function update(Request $request, Region $region): JsonResponse
    {
        $region->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'governortate_id' => ['sometimes', 'required', 'integer', 'exists:governortates,id'],
        ]));

        return $this->success($region->fresh(), 'Region updated successfully.');
    }

    public function destroy(Region $region): JsonResponse
    {
        $region->delete();

        return $this->success(message: 'Region deleted successfully.');
    }
}
