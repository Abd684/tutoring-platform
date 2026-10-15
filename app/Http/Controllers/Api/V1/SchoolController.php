<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', School::class);

        return $this->paginated(School::orderBy('name')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', School::class);

        $school = School::create($request->validate($this->rules()));

        return $this->success($school, 'School created successfully.', 201);
    }

    public function show(School $school): JsonResponse
    {
        $this->authorize('view', $school);

        return $this->success($school);
    }

    public function update(Request $request, School $school): JsonResponse
    {
        $this->authorize('update', $school);

        $school->update($request->validate($this->rules(true)));

        return $this->success($school->fresh(), 'School updated successfully.');
    }

    public function destroy(School $school): JsonResponse
    {
        $this->authorize('delete', $school);

        $school->delete();

        return $this->success(message: 'School deleted successfully.');
    }

    private function rules(bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'region_id' => [$presence, 'integer', 'exists:regions,id'],
            'student_id' => ['sometimes', 'nullable', 'integer', 'exists:students,id'],
        ];
    }
}
