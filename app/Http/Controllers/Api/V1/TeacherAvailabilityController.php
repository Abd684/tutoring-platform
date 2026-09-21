<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\TeacherAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherAvailabilityController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = TeacherAvailability::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $teacherAvailability = TeacherAvailability::create($request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            'date' => ['nullable', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'recurrence_type' => ['required', 'string', 'max:50'],
            'timezone' => ['required', 'timezone'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($teacherAvailability, 'Teacher Availability created successfully.', 201);
    }

    public function show(TeacherAvailability $teacherAvailability): JsonResponse
    {
        return $this->success($teacherAvailability);
    }

    public function update(Request $request, TeacherAvailability $teacherAvailability): JsonResponse
    {
        $teacherAvailability->update($request->validate([
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'weekday' => ['sometimes', 'nullable', 'integer', 'between:0,6'],
            'date' => ['sometimes', 'nullable', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i', 'after:start_time'],
            'recurrence_type' => ['sometimes', 'string', 'max:50'],
            'timezone' => ['sometimes', 'timezone'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($teacherAvailability->fresh(), 'Teacher Availability updated successfully.');
    }

    public function destroy(TeacherAvailability $teacherAvailability): JsonResponse
    {
        $teacherAvailability->delete();

        return $this->success(message: 'Teacher Availability deleted successfully.');
    }
}

