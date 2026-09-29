<?php

// deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Session;
use App\Models\TeacherAvailability;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SessionController extends ApiController
{
    /**
     * Get authenticated teacher ID.
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
     * Get session only if it belongs to
     * the currently authenticated teacher.
     */
    private function ownedSession(
        Request $request,
        Session $session
    ): Session {
        return Session::query()
            ->whereKey($session->getKey())
            ->where(
                'teacher_id',
                $this->currentTeacherId($request)
            )
            ->firstOrFail();
    }

    /**
     * Validate that availability belongs
     * to the authenticated teacher.
     */
    private function validateAvailabilityOwnership(
        int $teacherId,
        ?int $availabilityId
    ): void {
        if ($availabilityId === null) {
            return;
        }

        $exists = TeacherAvailability::query()
            ->whereKey($availabilityId)
            ->where('teacher_id', $teacherId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'availability_id' => [
                    'The selected availability does not belong to this teacher.',
                ],
            ]);
        }
    }

    /**
     * Validate that the group belongs
     * to one of the authenticated teacher subjects.
     */
    private function validateGroupOwnership(
        int $teacherId,
        ?int $groupId
    ): void {
        if ($groupId === null) {
            return;
        }

        $exists = DB::table('groups')
            ->join(
                'teacher_subjects',
                'groups.teacher_subject_id',
                '=',
                'teacher_subjects.id'
            )
            ->where('groups.id', $groupId)
            ->where(
                'teacher_subjects.teacher_id',
                $teacherId
            )
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'group_id' => [
                    'The selected group does not belong to this teacher.',
                ],
            ]);
        }
    }

    /**
     * Validate that enrollment belongs
     * to the authenticated teacher.
     */
    private function validateEnrollmentOwnership(
        int $teacherId,
        ?int $enrollmentId
    ): void {
        if ($enrollmentId === null) {
            return;
        }

        $exists = DB::table('enrollments')
            ->where('id', $enrollmentId)
            ->where('teacher_id', $teacherId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'enrollment_id' => [
                    'The selected enrollment does not belong to this teacher.',
                ],
            ]);
        }
    }

    /**
     * Validate GROUP / INDIVIDUAL structure
     * and return normalized capacity.
     */
    private function validateSessionStructure(
        string $type,
        ?int $groupId,
        ?int $enrollmentId,
        ?int $capacity
    ): int {
        /*
         * Individual session:
         *
         * enrollment_id required
         * group_id must be null
         * capacity = 1
         */
        if ($type === 'INDIVIDUAL') {
            if ($enrollmentId === null) {
                throw ValidationException::withMessages([
                    'enrollment_id' => [
                        'Enrollment is required for an individual session.',
                    ],
                ]);
            }

            if ($groupId !== null) {
                throw ValidationException::withMessages([
                    'group_id' => [
                        'Group must be null for an individual session.',
                    ],
                ]);
            }

            return 1;
        }

        /*
         * Group session:
         *
         * group_id required
         * enrollment_id must be null
         * capacity > 1
         */
        if ($groupId === null) {
            throw ValidationException::withMessages([
                'group_id' => [
                    'Group is required for a group session.',
                ],
            ]);
        }

        if ($enrollmentId !== null) {
            throw ValidationException::withMessages([
                'enrollment_id' => [
                    'Enrollment must be null for a group session.',
                ],
            ]);
        }

        if ($capacity === null || $capacity <= 1) {
            throw ValidationException::withMessages([
                'capacity' => [
                    'Group session capacity must be greater than 1.',
                ],
            ]);
        }

        return $capacity;
    }

    /**
     * Validate date range.
     */
    private function validateTimeRange(
        mixed $startsAt,
        mixed $endsAt
    ): void {
        $start = Carbon::parse($startsAt);
        $end = Carbon::parse($endsAt);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([
                'ends_at' => [
                    'The session end time must be after the start time.',
                ],
            ]);
        }
    }

    /**
     * Prevent teacher session overlap.
     */
    private function ensureNoOverlap(
        int $teacherId,
        mixed $startsAt,
        mixed $endsAt,
        ?int $ignoreSessionId = null
    ): void {
        $query = Session::query()
            ->where('teacher_id', $teacherId)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use (
                $startsAt,
                $endsAt
            ) {
                $query
                    ->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt);
            });

        if ($ignoreSessionId !== null) {
            $query->where('id', '!=', $ignoreSessionId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'starts_at' => [
                    'This session overlaps with another teacher session.',
                ],
            ]);
        }
    }

    /**
     * GET /api/v1/teacher/sessions
     *
     * Display current teacher sessions only.
     */
    public function index(Request $request): JsonResponse
    {
        $teacherId = $this->currentTeacherId($request);

        $filters = $request->validate([
            'type' => [
                'sometimes',
                Rule::in([
                    'INDIVIDUAL',
                    'GROUP',
                ]),
            ],

            'status' => [
                'sometimes',
                Rule::in([
                    'scheduled',
                    'ongoing',
                    'completed',
                    'cancelled',
                ]),
            ],
        ]);

        $query = Session::query()
            ->where('teacher_id', $teacherId);

        if (isset($filters['type'])) {
            $query->where(
                'type',
                $filters['type']
            );
        }

        if (isset($filters['status'])) {
            $query->where(
                'status',
                $filters['status']
            );
        }

        return $this->paginated(
            $query
                ->orderBy('starts_at')
                ->paginate(
                    $this->perPage($request)
                )
        );
    }

    /**
     * POST /api/v1/teacher/sessions
     *
     * Create session for current teacher.
     */
    public function store(Request $request): JsonResponse
    {
        $teacherId = $this->currentTeacherId($request);

        $validated = $request->validate([
            /*
             * teacher_id intentionally NOT accepted.
             */

            'availability_id' => [
                'nullable',
                'integer',
                'exists:teacher_availabilities,id',
            ],

            'type' => [
                'required',
                Rule::in([
                    'INDIVIDUAL',
                    'GROUP',
                ]),
            ],

            'group_id' => [
                'nullable',
                'integer',
                'exists:groups,id',
            ],

            'enrollment_id' => [
                'nullable',
                'integer',
                'exists:enrollments,id',
            ],

            'starts_at' => [
                'required',
                'date',
            ],

            'ends_at' => [
                'required',
                'date',
            ],

            'capacity' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        $availabilityId =
            $validated['availability_id'] ?? null;

        $groupId =
            $validated['group_id'] ?? null;

        $enrollmentId =
            $validated['enrollment_id'] ?? null;

        $capacity =
            $validated['capacity'] ?? null;

        /*
         * Validate ownership.
         */
        $this->validateAvailabilityOwnership(
            $teacherId,
            $availabilityId
        );

        /*
         * Validate GROUP / INDIVIDUAL structure.
         */
        $capacity = $this->validateSessionStructure(
            $validated['type'],
            $groupId,
            $enrollmentId,
            $capacity
        );

        /*
         * Check group/enrollment ownership
         * after determining session type.
         */
        if ($validated['type'] === 'GROUP') {
            $this->validateGroupOwnership(
                $teacherId,
                $groupId
            );
        }

        if ($validated['type'] === 'INDIVIDUAL') {
            $this->validateEnrollmentOwnership(
                $teacherId,
                $enrollmentId
            );
        }

        /*
         * Validate start/end.
         */
        $this->validateTimeRange(
            $validated['starts_at'],
            $validated['ends_at']
        );

        /*
         * Prevent teacher double-booking.
         */
        $this->ensureNoOverlap(
            $teacherId,
            $validated['starts_at'],
            $validated['ends_at']
        );

        /*
         * Teacher ID comes from authentication,
         * never from frontend.
         */
        $validated['teacher_id'] = $teacherId;

        /*
         * Always normalize capacity.
         */
        $validated['capacity'] = $capacity;

        /*
         * New sessions always start scheduled.
         */
        $validated['status'] = 'scheduled';

        $session = Session::create($validated);

        return $this->success(
            $session,
            'Session created successfully.',
            201
        );
    }

    /**
     * PATCH /api/v1/teacher/sessions/{session}
     *
     * Update current teacher session.
     */
    public function update(
        Request $request,
        Session $session
    ): JsonResponse {
        $session = $this->ownedSession(
            $request,
            $session
        );

        /*
         * Cancelled sessions cannot be edited.
         */
        if ($session->status === 'cancelled') {
            throw ValidationException::withMessages([
                'session' => [
                    'Cancelled sessions cannot be updated.',
                ],
            ]);
        }

        /*
         * Completed sessions cannot be edited.
         */
        if ($session->status === 'completed') {
            throw ValidationException::withMessages([
                'session' => [
                    'Completed sessions cannot be updated.',
                ],
            ]);
        }

        $validated = $request->validate([
            /*
             * teacher_id intentionally NOT accepted.
             */

            'availability_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:teacher_availabilities,id',
            ],

            'type' => [
                'sometimes',
                Rule::in([
                    'INDIVIDUAL',
                    'GROUP',
                ]),
            ],

            'group_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:groups,id',
            ],

            'enrollment_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:enrollments,id',
            ],

            'starts_at' => [
                'sometimes',
                'date',
            ],

            'ends_at' => [
                'sometimes',
                'date',
            ],

            'capacity' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            /*
             * cancelled is NOT allowed here.
             * Cancellation has its own endpoint.
             */
            'status' => [
                'sometimes',
                Rule::in([
                    'scheduled',
                    'ongoing',
                    'completed',
                ]),
            ],
        ]);

        $teacherId = $this->currentTeacherId($request);

        /*
         * Merge existing values with request
         * because PATCH can send only changed fields.
         */
        $type =
            $validated['type']
            ?? $session->type;

        $availabilityId = array_key_exists(
            'availability_id',
            $validated
        )
            ? $validated['availability_id']
            : $session->availability_id;

        $groupId = array_key_exists(
            'group_id',
            $validated
        )
            ? $validated['group_id']
            : $session->group_id;

        $enrollmentId = array_key_exists(
            'enrollment_id',
            $validated
        )
            ? $validated['enrollment_id']
            : $session->enrollment_id;

        $capacity = array_key_exists(
            'capacity',
            $validated
        )
            ? $validated['capacity']
            : $session->capacity;

        $startsAt =
            $validated['starts_at']
            ?? $session->starts_at;

        $endsAt =
            $validated['ends_at']
            ?? $session->ends_at;

        /*
         * Validate ownership.
         */
        $this->validateAvailabilityOwnership(
            $teacherId,
            $availabilityId
        );

        /*
         * Validate INDIVIDUAL / GROUP structure.
         */
        $capacity = $this->validateSessionStructure(
            $type,
            $groupId,
            $enrollmentId,
            $capacity
        );

        if ($type === 'GROUP') {
            $this->validateGroupOwnership(
                $teacherId,
                $groupId
            );
        }

        if ($type === 'INDIVIDUAL') {
            $this->validateEnrollmentOwnership(
                $teacherId,
                $enrollmentId
            );
        }

        /*
         * Validate final merged time range.
         */
        $this->validateTimeRange(
            $startsAt,
            $endsAt
        );

        /*
         * Check overlap only if session time changed.
         */
        if (
            array_key_exists('starts_at', $validated)
            || array_key_exists('ends_at', $validated)
        ) {
            $this->ensureNoOverlap(
                $teacherId,
                $startsAt,
                $endsAt,
                $session->id
            );
        }

        /*
         * Make sure individual session capacity
         * always remains 1.
         */
        $validated['capacity'] = $capacity;

        $session->update($validated);

        return $this->success(
            $session->fresh(),
            'Session updated successfully.'
        );
    }

    /**
     * POST /api/v1/sessions/{session}/cancel
     *
     * Cancel teacher session.
     */
    public function cancel(
        Request $request,
        Session $session
    ): JsonResponse {
        $session = $this->ownedSession(
            $request,
            $session
        );

        /*
         * Make cancellation idempotent.
         */
        if ($session->status === 'cancelled') {
            return $this->success(
                $session,
                'Session is already cancelled.'
            );
        }

        if ($session->status === 'completed') {
            throw ValidationException::withMessages([
                'session' => [
                    'Completed sessions cannot be cancelled.',
                ],
            ]);
        }

        $session->update([
            'status' => 'cancelled',
        ]);

        return $this->success(
            $session->fresh(),
            'Session cancelled successfully.'
        );
    }
}
