<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TeacherAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeacherAvailabilityController extends ApiController
{
    /**
     * Get current authenticated teacher ID.
     */
    private function currentTeacherId(Request $request): int
    {
        $teacherId = $request->user()?->teacher?->id;

        abort_if(
            ! $teacherId,
            403,
            'Teacher account not found.'
        );

        return (int) $teacherId;
    }

    /**
     * Make sure the availability belongs to current teacher.
     */
    private function ownedAvailability(
        Request $request,
        TeacherAvailability $teacherAvailability
    ): TeacherAvailability {
        return TeacherAvailability::query()
            ->whereKey($teacherAvailability->getKey())
            ->where('teacher_id', $this->currentTeacherId($request))
            ->firstOrFail();
    }

    /**
     * Display current teacher availability.
     */
    public function index(Request $request): JsonResponse
    {
        $teacherId = $this->currentTeacherId($request);

        $query = TeacherAvailability::query()
            ->where('teacher_id', $teacherId)
            ->orderBy('date')
            ->orderBy('weekday')
            ->orderBy('start_time');

        return $this->paginated(
            $query->paginate($this->perPage($request))
        );
    }

    /**
     * Add availability for current teacher.
     */
    public function store(Request $request): JsonResponse
    {
        $teacherId = $this->currentTeacherId($request);

        $validated = $request->validate([
            'weekday' => [
                'nullable',
                'integer',
                'between:0,6',
            ],

            'date' => [
                'nullable',
                'date',
            ],

            'start_time' => [
                'required',
                'date_format:H:i',
            ],

            'end_time' => [
                'required',
                'date_format:H:i',
                'after:start_time',
            ],

            'recurrence_type' => [
                'required',
                'string',
                'max:50',
            ],

            'timezone' => [
                'required',
                'timezone',
            ],

            'status' => [
                'sometimes',
                'string',
                'max:50',
            ],
        ]);

        // Important:
        // teacher_id comes from authenticated teacher,
        // never from request.
        $validated['teacher_id'] = $teacherId;

        $teacherAvailability = TeacherAvailability::create($validated);

        return $this->success(
            $teacherAvailability,
            'Teacher availability created successfully.',
            201
        );
    }

    /**
     * Show one availability record.
     */
    public function show(
        Request $request,
        TeacherAvailability $teacherAvailability
    ): JsonResponse {
        $teacherAvailability = $this->ownedAvailability(
            $request,
            $teacherAvailability
        );

        return $this->success($teacherAvailability);
    }

    /**
     * Update current teacher availability.
     */
    public function update(
        Request $request,
        TeacherAvailability $teacherAvailability
    ): JsonResponse {
        $teacherAvailability = $this->ownedAvailability(
            $request,
            $teacherAvailability
        );

        $validated = $request->validate([
            'weekday' => [
                'sometimes',
                'nullable',
                'integer',
                'between:0,6',
            ],

            'date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'start_time' => [
                'sometimes',
                'date_format:H:i',
            ],

            'end_time' => [
                'sometimes',
                'date_format:H:i',
            ],

            'recurrence_type' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'timezone' => [
                'sometimes',
                'timezone',
            ],

            'status' => [
                'sometimes',
                'string',
                'max:50',
            ],
        ]);

        /*
         * Validate end_time against either:
         * - new start_time
         * - or current start_time
         */
        $startTime = substr(
            (string) ($validated['start_time'] ?? $teacherAvailability->start_time),
            0,
            5
        );

        $endTime = substr(
            (string) ($validated['end_time'] ?? $teacherAvailability->end_time),
            0,
            5
        );

        if ($endTime <= $startTime) {
            throw ValidationException::withMessages([
                'end_time' => [
                    'End time must be after start time.',
                ],
            ]);
        }

        $teacherAvailability->update($validated);

        return $this->success(
            $teacherAvailability->fresh(),
            'Teacher availability updated successfully.'
        );
    }

    /**
     * Delete current teacher availability.
     */
    public function destroy(
        Request $request,
        TeacherAvailability $teacherAvailability
    ): JsonResponse {
        $teacherAvailability = $this->ownedAvailability(
            $request,
            $teacherAvailability
        );

        $teacherAvailability->delete();

        return $this->success(
            message: 'Teacher availability deleted successfully.'
        );
    }
}
