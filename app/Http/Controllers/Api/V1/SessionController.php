<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Session::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $session = Session::create($request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'availability_id' => ['nullable', 'integer', 'exists:teacher_availabilities,id'],
            'type' => ['required', 'string', 'max:50'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($session, 'Session created successfully.', 201);
    }

    public function show(Session $session): JsonResponse
    {
        return $this->success($session);
    }

    public function update(Request $request, Session $session): JsonResponse
    {
        $session->update($request->validate([
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'availability_id' => ['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id'],
            'type' => ['sometimes', 'string', 'max:50'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'enrollment_id' => ['sometimes', 'nullable', 'integer', 'exists:enrollments,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($session->fresh(), 'Session updated successfully.');
    }

    public function destroy(Session $session): JsonResponse
    {
        $session->delete();

        return $this->success(message: 'Session deleted successfully.');
    }
}

