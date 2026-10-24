<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\PaidLessonBookings;
use App\Services\SessionRules;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $request->validate(['type' => ['required'], 'starts_at' => ['required'], 'ends_at' => ['required']]);
        $data = $request->validate([
            'teacher_id' => ['prohibited'], 'type' => ['sometimes', 'in:INDIVIDUAL,GROUP'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'enrollment_id' => ['sometimes', 'nullable', 'integer', 'exists:enrollments,id'],
            'availability_id' => ['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id'],
            'starts_at' => ['sometimes', 'date'], 'ends_at' => ['sometimes', 'date'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'], 'status' => ['sometimes', 'in:scheduled,ongoing,completed'],
        ]);

        return DB::transaction(function () use ($data, $request) {
            $teacherId = $this->currentTeacherId($request);
            Teacher::whereKey($teacherId)->lockForUpdate()->firstOrFail();
            if (! empty($data['enrollment_id'])) {
                $e = Enrollment::findOrFail($data['enrollment_id']);
                Student::whereKey($e->student_id)->lockForUpdate()->firstOrFail();
            }
            $state = app(SessionRules::class)->validate([...$data, 'teacher_id' => $teacherId, 'status' => 'scheduled']);
            abort_if(app(SessionRules::class)->conflicts($state), 409, 'Teacher or student already has an overlapping session.');

            return $this->success(Session::create($state), 'Session created successfully.', 201);
        }, 3);
    }

    /**
     * Student booking endpoint:
     * POST /api/v1/sessions/{id}/book
     */
    public function book(Request $request, int $id): JsonResponse
    {
        $this->authorize('book', Session::class);

        return $this->businessError('Book a teacher availability using POST /api/v1/teacher-availabilities/{availability}/book.', 409);
    }

    /**
     * Student booking cancellation:
     * DELETE /api/v1/sessions/{id}/booking
     */
    public function cancelBooking(Request $request, int $id): JsonResponse
    {
        $session = Session::findOrFail($id);
        $this->authorize('studentView', $session);

        if ($session->enrollment?->enrollable_type === 'paid_booking') {
            return $this->success(app(PaidLessonBookings::class)->cancelSession($session, 'student', $request->user()->id), 'Paid lesson cancelled.');
        }

        return DB::transaction(function () use ($session) {
            Teacher::whereKey($session->teacher_id)->lockForUpdate()->firstOrFail();
            $session = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->authorize('studentView', $session);
            abort_unless($session->type === 'INDIVIDUAL', 404);
            if ($session->status === 'cancelled') {
                return $this->success($session);
            }
            abort_unless($session->status === 'scheduled' && $session->starts_at->isFuture(), 409, 'Booking can no longer be cancelled.');
            $session->update(['status' => 'cancelled']);

            return $this->success($session->fresh(), 'Booking cancelled successfully.');
        }, 3);
    }

    /**
     * Teacher cancels the session itself.
     */
    public function cancel(
        Session $session
    ): JsonResponse {
        $this->authorize('cancel', $session);

        if ($session->enrollment?->enrollable_type === 'paid_booking') {
            return $this->success(app(PaidLessonBookings::class)->cancelSession($session, 'teacher', request()->user()->id), 'Paid lesson cancelled.');
        }

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
    public function update(Request $request, Session $session): JsonResponse
    {
        $this->authorize('update', $session);
        $data = $request->validate([
            'teacher_id' => ['prohibited'], 'type' => ['sometimes', 'in:INDIVIDUAL,GROUP'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'enrollment_id' => ['sometimes', 'nullable', 'integer', 'exists:enrollments,id'],
            'availability_id' => ['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id'],
            'starts_at' => ['sometimes', 'date'], 'ends_at' => ['sometimes', 'date'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'], 'status' => ['sometimes', 'in:scheduled,ongoing,completed'],
        ]);

        return DB::transaction(function () use ($session, $data) {
            Teacher::whereKey($session->teacher_id)->lockForUpdate()->firstOrFail();
            $session = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($session->status, ['cancelled', 'completed'], true), 409, 'Session is closed.');
            $state = app(SessionRules::class)->validate(array_replace($session->only(['teacher_id', 'type', 'group_id', 'enrollment_id', 'availability_id', 'starts_at', 'ends_at', 'capacity', 'status']), $data));
            if (! empty($state['enrollment_id'])) {
                $e = Enrollment::findOrFail($state['enrollment_id']);
                Student::whereKey($e->student_id)->lockForUpdate()->firstOrFail();
            }
            abort_if(($state['status'] ?? null) === 'completed' && Carbon::parse($state['ends_at'])->isFuture(), 422, 'Session has not ended yet.');
            abort_if(app(SessionRules::class)->conflicts($state, $session->id), 409, 'Teacher or student already has an overlapping session.');
            $session->update($state);

            return $this->success($session->fresh(), 'Session updated successfully.');
        }, 3);
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

        abort_if($session->enrollment?->enrollable_type === 'paid_booking', 409, 'Paid lesson history must be retained. Use cancellation.');

        $session->delete();

        return $this->success(
            message: 'Session deleted successfully.'
        );
    }
}
