<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Session;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Services\SessionRules;
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
                'required_if:type,GROUP',
                'prohibited_if:type,INDIVIDUAL',
                'nullable',
                'integer',
                'exists:groups,id',
            ],

            'enrollment_id' => [
                'required_if:type,INDIVIDUAL',
                'prohibited_if:type,GROUP',
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
            $this->authorize('manage', Group::findOrFail($validated['group_id']));
        }

        if (! empty($validated['availability_id'])) {
            $this->authorize('view', TeacherAvailability::findOrFail($validated['availability_id']));
        }

        if (! empty($validated['enrollment_id'])) {
            $this->authorize('manage', Enrollment::findOrFail($validated['enrollment_id']));
        }

        /*
         * Never take these from the frontend.
         */
        $validated['teacher_id'] = $teacherId;
        $validated['status'] = 'scheduled';

        $session = DB::transaction(function () use ($validated, $teacherId) {
            Teacher::whereKey($teacherId)->lockForUpdate()->firstOrFail();
            $session = new Session($validated);
            app(SessionRules::class)->validate($session);
            $session->save();

            return $session;
        }, 3);

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

        return response()->json(['status' => false, 'message' => 'Book an availability using POST /teachers/{teacher}/bookings.', 'code' => 'USE_AVAILABILITY_BOOKING'], 410);
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
            function () use ($id) {
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

                $this->authorize('cancelBooking', $session);

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
                    'status' => 'cancelled',
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
            $this->authorize('view', TeacherAvailability::findOrFail($validated['availability_id']));
        }

        if (
            array_key_exists('group_id', $validated)
            && $validated['group_id'] !== null
        ) {
            $this->authorize('manage', Group::findOrFail($validated['group_id']));
        }

        if (
            array_key_exists('enrollment_id', $validated)
            && $validated['enrollment_id'] !== null
        ) {
            $this->authorize('manage', Enrollment::findOrFail($validated['enrollment_id']));
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

        if (($validated['status'] ?? null) === 'completed' && $endAt?->isFuture()) {
            throw ValidationException::withMessages(['status' => ['Session has not ended yet.']]);
        }
        DB::transaction(function () use ($session, $validated, $teacherId) {
            Teacher::whereKey($teacherId)->lockForUpdate()->firstOrFail();
            $locked = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($locked->status, ['cancelled', 'completed'], true), 409);
            $locked->fill($validated);
            app(SessionRules::class)->validate($locked);
            $locked->save();
        }, 3);

        return $this->success(
            $session->fresh(),
            'Session updated successfully.'
        );
    }

    public function complete(Request $request, Session $session): JsonResponse
    {
        $this->authorize('update', $session);

        return DB::transaction(function () use ($session) {
            $session = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if($session->status === 'cancelled', 409, 'Cancelled session cannot be completed.');
            abort_if($session->ends_at->isFuture(), 422, 'Session has not ended yet.');
            $session->update(['status' => 'completed']);

            return $this->success($session->fresh(), 'Session completed successfully.');
        });
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
