<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Governortate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GovernortateController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return $this->paginated(Governortate::orderBy('name')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $governortate = Governortate::create($request->validate(['name' => ['required', 'string', 'max:255']]));

        return $this->success($governortate, 'Governortate created successfully.', 201);
    }

    public function show(Governortate $governortate): JsonResponse
    {
        return $this->success($governortate);
    }

    public function update(Request $request, Governortate $governortate): JsonResponse
    {
        $governortate->update($request->validate(['name' => ['sometimes', 'required', 'string', 'max:255']]));

        return $this->success($governortate->fresh(), 'Governortate updated successfully.');
    }

    public function destroy(Governortate $governortate): JsonResponse
    {
        $governortate->delete();

        return $this->success(message: 'Governortate deleted successfully.');
    }
}
