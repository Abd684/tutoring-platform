<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $attendance = Attendance::create($request->validate([
            'session_id' => ['required', 'integer', 'exists:sessions,id'],
            'enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'status' => ['required', 'string', 'max:50'],
            'check_in_at' => ['nullable', 'date'],
            'check_out_at' => ['nullable', 'date', 'after_or_equal:check_in_at'],
            'note' => ['nullable', 'string'],
        ]));

        return $this->success($attendance, 'Attendance created successfully.', 201);
    }

    public function show(Attendance $attendance): JsonResponse
    {
        return $this->success($attendance);
    }

    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        $attendance->update($request->validate([
            'session_id' => ['sometimes', 'integer', 'exists:sessions,id'],
            'enrollment_id' => ['sometimes', 'integer', 'exists:enrollments,id'],
            'status' => ['sometimes', 'string', 'max:50'],
            'check_in_at' => ['sometimes', 'nullable', 'date'],
            'check_out_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:check_in_at'],
            'note' => ['sometimes', 'nullable', 'string'],
        ]));

        return $this->success($attendance->fresh(), 'Attendance updated successfully.');
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $attendance->delete();

        return $this->success(message: 'Attendance deleted successfully.');
    }
}

