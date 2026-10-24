<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaidBooking;
use App\Models\TeacherAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeacherAvailabilityController extends ApiController
{
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

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TeacherAvailability::class);

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
        $this->authorize('create', TeacherAvailability::class);

        $teacherId = $this->currentTeacherId($request);

        $validated = $request->validate([
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
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
                'in:none,weekly,custom',
            ],

            'timezone' => [
                'required',
                'timezone',
            ],

            'status' => [
                'sometimes',
                'in:active,disabled',
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
        TeacherAvailability $teacherAvailability
    ): JsonResponse {
        $this->authorize('view', $teacherAvailability);

        return $this->success($teacherAvailability);
    }

    /**
     * Update current teacher availability.
     */
    public function update(
        Request $request,
        TeacherAvailability $teacherAvailability
    ): JsonResponse {
        $this->authorize('update', $teacherAvailability);

        $validated = $request->validate([
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
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
                'in:none,weekly,custom',
            ],

            'timezone' => [
                'sometimes',
                'timezone',
            ],

            'status' => [
                'sometimes',
                'in:active,disabled',
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
        TeacherAvailability $teacherAvailability
    ): JsonResponse {
        $this->authorize('delete', $teacherAvailability);

        abort_if(PaidBooking::where('availability_id', $teacherAvailability->id)->exists(), 409, 'Availability linked to payment history cannot be deleted. Disable it instead.');

        $teacherAvailability->delete();

        return $this->success(
            message: 'Teacher availability deleted successfully.'
        );
    }
}
