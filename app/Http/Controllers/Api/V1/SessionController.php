<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Session;
use App\Models\Student;
use App\Models\TeacherAvailability;
use App\Services\SessionEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
     * Teacher sessions list.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Session::class);

        $teacherId = $this->currentTeacherId($request);

        $query = Session::query()
            ->where('teacher_id', $teacherId)
            ->orderByDesc('id');

        return $this->paginated(
            $query->paginate($this->perPage($request))
        );
    }

    /**
     * Create session for authenticated teacher.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Session::class);

        $teacherId = $this->currentTeacherId($request);

        $validated = $request->validate([
            'teacher_id' => ['prohibited'],
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
                'after:starts_at',
            ],

            'capacity' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        /*
         * Any related resource must belong
         * to the authenticated teacher.
         */
        if (! empty($validated['group_id'])) {
            $exists = Group::query()
                ->whereKey($validated['group_id'])
                ->whereHas(
                    'teacherSubject',
                    fn ($query) => $query->where(
                        'teacher_id',
                        $teacherId
                    )
                )
                ->exists();

            abort_unless(
                $exists,
                404,
                'Group not found.'
            );
        }

        if (! empty($validated['availability_id'])) {
            $exists = TeacherAvailability::query()
                ->whereKey($validated['availability_id'])
                ->where('teacher_id', $teacherId)
                ->exists();

            abort_unless(
                $exists,
                404,
                'Teacher availability not found.'
            );
        }

        if (! empty($validated['enrollment_id'])) {
            $exists = Enrollment::query()
                ->whereKey($validated['enrollment_id'])
                ->where('teacher_id', $teacherId)
                ->exists();

            abort_unless(
                $exists,
                404,
                'Enrollment not found.'
            );
        }

        /*
         * Never take these from the frontend.
         */
        $validated['teacher_id'] = $teacherId;
        $validated['status'] = 'scheduled';

        $session = Session::create($validated);

        return $this->success(
            $session,
            'Session created successfully.',
            201
        );
    }

    /**
     * Student booking endpoint:
     * POST /api/v1/sessions/{id}/book
     */
    public function book(Request $request, int $id): JsonResponse
    {
        $this->authorize('book', Session::class);

        $student = $request->user()?->student;

        if (! $student) {
            return response()->json([
                'status' => false,
                'message' => 'Student account not found.',
                'code' => 'STUDENT_PROFILE_NOT_FOUND',
            ], 403);
        }

        $validated = $request->validate([
            'enrollment_id' => [
                'nullable',
                'integer',
                'exists:enrollments,id',
            ],
        ]);

        abort_unless(
            $student->status === 'active'
                && $request->user()->status === 'active',
            403
        );

        $result = DB::transaction(
            function () use ($student, $validated, $id) {
                Student::query()
                    ->whereKey($student->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $session = Session::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (
                    $session->teacher?->status !== 'active'
                    || $session->teacher->user?->status !== 'active'
                ) {
                    return [
                        'error' => [
                            'The teacher is not available.',
                            'TEACHER_NOT_AVAILABLE',
                            409,
                        ],
                    ];
                }

                if ($session->type !== 'INDIVIDUAL') {
                    return [
                        'error' => [
                            'This endpoint books individual sessions only.',
                            'SESSION_TYPE_NOT_BOOKABLE',
                            422,
                        ],
                    ];
                }

                if ($session->status !== 'scheduled') {
                    return [
                        'error' => [
                            'Only scheduled sessions can be booked.',
                            'SESSION_NOT_AVAILABLE',
                            409,
                        ],
                    ];
                }

                if (
                    $session->starts_at !== null
                    && $session->starts_at->lte(now())
                ) {
                    return [
                        'error' => [
                            'Past or started sessions cannot be booked.',
                            'SESSION_ALREADY_STARTED',
                            409,
                        ],
                    ];
                }

                if ($session->enrollment_id !== null) {
                    $existing = Enrollment::find(
                        $session->enrollment_id
                    );

                    $code =
                        $existing
                        && (int) $existing->student_id
                            === (int) $student->id
                            ? 'ALREADY_BOOKED_BY_STUDENT'
                            : 'SESSION_ALREADY_BOOKED';

                    return [
                        'error' => [
                            'This session is already booked.',
                            $code,
                            409,
                        ],
                    ];
                }

                $eligibility = app(SessionEligibility::class);

                $query = $eligibility->enrollments(
                    $student->id,
                    $session
                );

                if (! empty($validated['enrollment_id'])) {
                    $enrollment = (clone $query)
                        ->whereKey($validated['enrollment_id'])
                        ->first();

                    if (! $enrollment) {
                        return [
                            'error' => [
                                'The selected enrollment is not an active enrollment for this student and teacher.',
                                'INVALID_ENROLLMENT',
                                422,
                            ],
                        ];
                    }
                } else {
                    $matches = $query
                        ->orderBy('id')
                        ->limit(2)
                        ->get();

                    if ($matches->isEmpty()) {
                        return [
                            'error' => [
                                'No active enrollment is available for this teacher.',
                                'NO_ACTIVE_ENROLLMENT',
                                422,
                            ],
                        ];
                    }

                    if ($matches->count() > 1) {
                        return [
                            'error' => [
                                'More than one active enrollment matches this teacher. Send enrollment_id in the request body.',
                                'ENROLLMENT_SELECTION_REQUIRED',
                                422,
                            ],
                        ];
                    }

                    $enrollment = $matches->first();
                }

                $hasConflict = $eligibility
                    ->conflicts(
                        $student->id,
                        $session
                    )
                    ->exists();

                if ($hasConflict) {
                    return [
                        'error' => [
                            'The student already has another session that overlaps this time.',
                            'SESSION_TIME_CONFLICT',
                            409,
                        ],
                    ];
                }

                $session->update([
                    'enrollment_id' => $enrollment->id,
                    'capacity' => 1,
                ]);

                return [
                    'session' => $session
                        ->fresh()
                        ->load([
                            'teacher.user:id,name,email',
                            'enrollment',
                        ]),
                ];
            }
        );

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];

            return response()->json([
                'status' => false,
                'message' => $message,
                'code' => $code,
            ], $status);
        }

        return $this->success(
            $result['session'],
            'Session booked successfully.'
        );
    }

    /**
     * Student booking cancellation:
     * DELETE /api/v1/sessions/{id}/booking
     */
    public function cancelBooking(
        Request $request,
        int $id
    ): JsonResponse {
        $this->authorize('cancelBooking', Session::class);

        $student = $request->user()?->student;

        if (! $student) {
            return response()->json([
                'status' => false,
                'message' => 'Student account not found.',
                'code' => 'STUDENT_PROFILE_NOT_FOUND',
            ], 403);
        }

        $result = DB::transaction(
            function () use ($student, $id) {
                $session = Session::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                if (
                    $session->type !== 'INDIVIDUAL'
                    || $session->enrollment_id === null
                ) {
                    return [
                        'error' => [
                            'This session has no student booking to cancel.',
                            'BOOKING_NOT_FOUND',
                            404,
                        ],
                    ];
                }

                $enrollment = Enrollment::find(
                    $session->enrollment_id
                );

                if (
                    ! $enrollment
                    || (int) $enrollment->student_id
                        !== (int) $student->id
                ) {
                    return [
                        'error' => [
                            'This booking does not belong to the authenticated student.',
                            'BOOKING_FORBIDDEN',
                            403,
                        ],
                    ];
                }

                if ($session->status !== 'scheduled') {
                    return [
                        'error' => [
                            'Only scheduled bookings can be cancelled.',
                            'BOOKING_CANNOT_BE_CANCELLED',
                            409,
                        ],
                    ];
                }

                if (
                    $session->starts_at !== null
                    && $session->starts_at->lte(now())
                ) {
                    return [
                        'error' => [
                            'A started or past session cannot be cancelled.',
                            'BOOKING_ALREADY_STARTED',
                            409,
                        ],
                    ];
                }

                $session->update([
                    'enrollment_id' => null,
                ]);

                return [
                    'session' => $session->fresh(),
                ];
            }
        );

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];

            return response()->json([
                'status' => false,
                'message' => $message,
                'code' => $code,
            ], $status);
        }

        return $this->success(
            $result['session'],
            'Session booking cancelled successfully.'
        );
    }

    /**
     * Teacher cancels the session itself.
     */
    public function cancel(
    Session $session
): JsonResponse {
    $this->authorize('cancel', $session);

    $result = DB::transaction(
        function () use ($session) {
            $session = Session::query()
                ->lockForUpdate()
                ->findOrFail($session->id);

            /*
             * Idempotent cancellation.
             */
            if ($session->status === 'cancelled') {
                return [
                    'session' => $session->fresh(),
                    'already_cancelled' => true,
                ];
            }

            /*
             * Completed session cannot be cancelled.
             */
            if ($session->status === 'completed') {
                return [
                    'error' => [
                        'A completed session cannot be cancelled.',
                        'SESSION_ALREADY_COMPLETED',
                        409,
                    ],
                ];
            }

            /*
             * Cancellation has its own endpoint.
             */
            $session->update([
                'status' => 'cancelled',
            ]);

            return [
                'session' => $session->fresh(),
                'already_cancelled' => false,
            ];
        }
    );

    if (isset($result['error'])) {
        [$message, $code, $status] = $result['error'];

        return response()->json([
            'status' => false,
            'message' => $message,
            'code' => $code,
        ], $status);
    }

    return $this->success(
        $result['session'],
        $result['already_cancelled']
            ? 'Session is already cancelled.'
            : 'Session cancelled successfully.'
    );
}

    /**
     * Show teacher session.
     */
    public function show(Session $session): JsonResponse
    {
        $this->authorize('view', $session);

        return $this->success($session);
    }

    /**
     * Update teacher session.
     */
    public function update(
        Request $request,
        Session $session
    ): JsonResponse {
        $this->authorize('update', $session);

        /*
         * Closed sessions cannot be edited.
         */
        if (in_array(
            $session->status,
            ['cancelled', 'completed'],
            true
        )) {
            return response()->json([
                'status' => false,
                'message' => 'Cancelled or completed sessions cannot be updated.',
                'code' => 'SESSION_NOT_EDITABLE',
            ], 409);
        }

        $teacherId = $this->currentTeacherId($request);

        $validated = $request->validate([
            'teacher_id' => [
                'prohibited',
            ],

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

            'status' => [
                'sometimes',
                Rule::in([
                    'scheduled',
                    'ongoing',
                    'completed',
                ]),
            ],
        ]);

        /*
         * If related resources are changed,
         * they must still belong to this teacher.
         */
        if (
            array_key_exists('availability_id', $validated)
            && $validated['availability_id'] !== null
        ) {
            $exists = TeacherAvailability::query()
                ->whereKey($validated['availability_id'])
                ->where('teacher_id', $teacherId)
                ->exists();

            abort_unless(
                $exists,
                404,
                'Teacher availability not found.'
            );
        }

        if (
            array_key_exists('group_id', $validated)
            && $validated['group_id'] !== null
        ) {
            $exists = Group::query()
                ->whereKey($validated['group_id'])
                ->whereHas(
                    'teacherSubject',
                    fn ($query) => $query->where(
                        'teacher_id',
                        $teacherId
                    )
                )
                ->exists();

            abort_unless(
                $exists,
                404,
                'Group not found.'
            );
        }

        if (
            array_key_exists('enrollment_id', $validated)
            && $validated['enrollment_id'] !== null
        ) {
            $exists = Enrollment::query()
                ->whereKey($validated['enrollment_id'])
                ->where('teacher_id', $teacherId)
                ->exists();

            abort_unless(
                $exists,
                404,
                'Enrollment not found.'
            );
        }

        /*
         * Correct validation for partial updates.
         */
        $startAt = array_key_exists('starts_at', $validated)
            ? Carbon::parse($validated['starts_at'])
            : $session->starts_at;

        $endAt = array_key_exists('ends_at', $validated)
            ? Carbon::parse($validated['ends_at'])
            : $session->ends_at;

        if (
            $startAt !== null
            && $endAt !== null
            && $endAt->lte($startAt)
        ) {
            throw ValidationException::withMessages([
                'ends_at' => [
                    'End time must be after start time.',
                ],
            ]);
        }

        $session->update($validated);

        return $this->success(
            $session->fresh(),
            'Session updated successfully.'
        );
    }

    /**
     * Delete teacher session.
     */
    public function destroy(Session $session): JsonResponse
    {
        $this->authorize('delete', $session);

        $session->delete();

        return $this->success(
            message: 'Session deleted successfully.'
        );
    }
}
