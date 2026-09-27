<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\TeacherAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherAvailabilityController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = TeacherAvailability::query()
            ->with(['teacher.user:id,name,email']);

        // Keep the current project principle: teacher_id comes from the frontend.
        // GET /api/v1/teacher/availability?teacher_id=5
        $query->when(
            $request->filled('teacher_id'),
            fn ($q) => $q->where('teacher_id', $request->integer('teacher_id'))
        )->when(
            $request->filled('weekday'),
            fn ($q) => $q->where('weekday', $request->integer('weekday'))
        )->when(
            $request->filled('date'),
            fn ($q) => $q->whereDate('date', $request->date('date'))
        )->when(
            $request->filled('status'),
            fn ($q) => $q->where('status', $request->string('status'))
        );

        return $this->paginated(
            $query->orderBy('date')
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->orderBy('id')
                ->paginate($this->perPage($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Keep the current project principle: teacher_id is sent by the frontend.
        $teacherAvailability = TeacherAvailability::create($request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            'date' => ['nullable', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'recurrence_type' => ['required', Rule::in(['none', 'weekly', 'custom'])],
            'timezone' => ['required', 'timezone'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ]));

        return $this->success(
            $teacherAvailability->load(['teacher.user:id,name,email']),
            'Teacher Availability created successfully.',
            201
        );
    }

    /**
     * Teacher-scoped availability list for GET /api/v1/teacher/availability.
     * The teacher is derived from the authenticated Bearer token.
     */
    public function myAvailability(Request $request): JsonResponse
    {
        $teacher = $request->user()?->teacher;

        if (! $teacher) {
            return response()->json([
                'status' => false,
                'message' => 'Teacher account not found.',
                'code' => 'TEACHER_PROFILE_NOT_FOUND',
            ], 403);
        }

        $query = TeacherAvailability::query()
            ->with(['teacher.user:id,name,email'])
            ->where('teacher_id', $teacher->id);

        $query->when(
            $request->filled('weekday'),
            fn ($q) => $q->where('weekday', $request->integer('weekday'))
        )->when(
            $request->filled('date'),
            fn ($q) => $q->whereDate('date', $request->date('date'))
        )->when(
            $request->filled('status'),
            fn ($q) => $q->where('status', $request->string('status'))
        );

        return $this->paginated(
            $query->orderBy('date')
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->orderBy('id')
                ->paginate($this->perPage($request))
        );
    }

    /**
     * Teacher-scoped availability creation for POST /api/v1/teacher/availability.
     * teacher_id is NOT accepted as the source of identity; it comes from the token.
     * If the frontend still sends teacher_id, it is ignored because only validated
     * fields below are used to create the record.
     */
    public function storeMyAvailability(Request $request): JsonResponse
    {
        $teacher = $request->user()?->teacher;

        if (! $teacher) {
            return response()->json([
                'status' => false,
                'message' => 'Teacher account not found.',
                'code' => 'TEACHER_PROFILE_NOT_FOUND',
            ], 403);
        }

        $validated = $request->validate([
            'weekday' => ['nullable', 'integer', 'between:0,6'],
            'date' => ['nullable', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'recurrence_type' => ['required', Rule::in(['none', 'weekly', 'custom'])],
            'timezone' => ['required', 'timezone'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ]);

        $validated['teacher_id'] = $teacher->id;

        $teacherAvailability = TeacherAvailability::create($validated);

        return $this->success(
            $teacherAvailability->load(['teacher.user:id,name,email']),
            'Teacher Availability created successfully.',
            201
        );
    }
    public function show(TeacherAvailability $teacherAvailability): JsonResponse
    {
        return $this->success($teacherAvailability->load(['teacher.user:id,name,email']));
    }

    public function update(Request $request, TeacherAvailability $teacherAvailability): JsonResponse
    {
        $teacherAvailability->update($request->validate([
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'weekday' => ['sometimes', 'nullable', 'integer', 'between:0,6'],
            'date' => ['sometimes', 'nullable', 'date'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i', 'after:start_time'],
            'recurrence_type' => ['sometimes', Rule::in(['none', 'weekly', 'custom'])],
            'timezone' => ['sometimes', 'timezone'],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
        ]));

        return $this->success(
            $teacherAvailability->fresh()->load(['teacher.user:id,name,email']),
            'Teacher Availability updated successfully.'
        );
    }

    public function destroy(TeacherAvailability $teacherAvailability): JsonResponse
    {
        $teacherAvailability->delete();

        return $this->success(message: 'Teacher Availability deleted successfully.');
    }
}
