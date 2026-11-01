<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use App\Models\Individual;
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

        $data = $request->validate([
            'scope' => ['sometimes', 'in:today'], 'date' => ['sometimes', 'date_format:Y-m-d', 'prohibits:scope'],
            'timezone' => ['sometimes', 'timezone'], 'type' => ['required_with:type_id', 'in:group,individual'],
            'type_id' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', 'in:scheduled,ongoing,completed,cancelled'],
        ]);
        if (isset($data['type_id'])) {
            $class = $data['type'] === 'group' ? Group::class : Individual::class;
            $this->authorize('manage', $class::findOrFail($data['type_id']));
        }
        $query = Session::where('teacher_id', $teacherId);
        if (isset($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (isset($data['type_id'])) {
            $query->where('type_id', $data['type_id']);
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['scope']) || isset($data['date'])) {
            $timezone = $data['timezone'] ?? 'Asia/Damascus';
            $day = isset($data['date']) ? Carbon::createFromFormat('!Y-m-d', $data['date'], $timezone) : Carbon::now($timezone)->startOfDay();
            $end = $day->copy()->addDay()->setTimezone(config('app.timezone'));
            $start = $day->copy()->setTimezone(config('app.timezone'));
            $query->where('starts_at', '<', $end)->where('ends_at', '>', $start)->orderBy('starts_at')->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }

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
        $request->validate(['type' => ['required'], 'type_id' => ['required'], 'starts_at' => ['required'], 'ends_at' => ['required']]);
        $data = $request->validate([
            'teacher_id' => ['prohibited'], 'type' => ['sometimes', 'in:individual,group'],
            'type_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'group_id' => ['prohibited'], 'enrollment_id' => ['prohibited'], 'individual_id' => ['prohibited'],
            'availability_id' => ['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id'],
            'starts_at' => ['sometimes', 'date'], 'ends_at' => ['sometimes', 'date'],
            'progress_notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'], 'status' => ['sometimes', 'in:scheduled,ongoing,completed'],
        ]);

        return DB::transaction(function () use ($data, $request) {
            $teacherId = $this->currentTeacherId($request);
            Teacher::whereKey($teacherId)->lockForUpdate()->firstOrFail();
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

        if ($session->paidBooking()->exists()) {
            return $this->success(app(PaidLessonBookings::class)->cancelSession($session, 'student', $request->user()->id), 'Paid lesson cancelled.');
        }

        return DB::transaction(function () use ($session) {
            Teacher::whereKey($session->teacher_id)->lockForUpdate()->firstOrFail();
            $session = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->authorize('studentView', $session);
            abort_unless($session->type === 'individual', 404);
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

        if ($session->paidBooking()->exists()) {
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
        if ($request->has('progress_notes') && count($request->except(['_token', '_method'])) === 1) {
            return $this->progress($request, $session);
        }
        abort_if($session->paidBooking()->exists(), 409, 'Paid lessons must use their rescheduling workflow.');
        $data = $request->validate([
            'teacher_id' => ['prohibited'], 'type' => ['sometimes', 'in:individual,group'],
            'type_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'group_id' => ['prohibited'], 'enrollment_id' => ['prohibited'], 'individual_id' => ['prohibited'],
            'availability_id' => ['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id'],
            'starts_at' => ['sometimes', 'date'], 'ends_at' => ['sometimes', 'date'],
            'progress_notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'], 'status' => ['sometimes', 'in:scheduled,ongoing,completed'],
        ]);

        abort_if(isset($data['type']) && $data['type'] !== $session->type && ! isset($data['type_id']), 422, 'Changing type also requires type_id.');

        return DB::transaction(function () use ($session, $data) {
            Teacher::whereKey($session->teacher_id)->lockForUpdate()->firstOrFail();
            $session = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($session->status, ['cancelled', 'completed'], true), 409, 'Session is closed.');
            abort_if($session->attendance()->exists() && ((isset($data['type']) && $data['type'] !== $session->type) || (isset($data['type_id']) && (int) $data['type_id'] !== (int) $session->type_id)), 409, 'A session with attendance cannot change participants.');
            $state = app(SessionRules::class)->validate(array_replace($session->only(['teacher_id', 'type', 'type_id', 'availability_id', 'starts_at', 'ends_at', 'capacity', 'status']), $data));
            abort_if(($state['status'] ?? null) === 'completed' && Carbon::parse($state['ends_at'])->isFuture(), 422, 'Session has not ended yet.');
            abort_if(app(SessionRules::class)->conflicts($state, $session->id), 409, 'Teacher or student already has an overlapping session.');
            $session->update($state);

            return $this->success($session->fresh(), 'Session updated successfully.');
        }, 3);
    }

    public function progress(Request $request, Session $session): JsonResponse
    {
        $this->authorize('update', $session);
        $data = $request->validate(['progress_notes' => ['present', 'nullable', 'string', 'max:10000']]);

        return DB::transaction(function () use ($session, $data) {
            Teacher::whereKey($session->teacher_id)->lockForUpdate()->firstOrFail();
            $session = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if($session->status === 'cancelled', 409, 'Cancelled sessions cannot record progress.');
            $session->update($data);

            return $this->success($session->fresh(), 'Teaching progress updated.');
        });
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

        abort_if($session->paidBooking()->exists(), 409, 'Paid lesson history must be retained. Use cancellation.');

        $session->delete();

        return $this->success(
            message: 'Session deleted successfully.'
        );
    }
}
