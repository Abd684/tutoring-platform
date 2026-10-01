<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Session::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $session = Session::create($request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'availability_id' => ['nullable', 'integer', 'exists:teacher_availabilities,id'],
            'type' => ['required', 'string', 'max:50'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($session, 'Session created successfully.', 201);
    }

    /**
     * Student booking endpoint from the SDD:
     * POST /api/v1/sessions/{id}/book
     *
     * The student comes from the Bearer token. For an INDIVIDUAL session, the
     * session is considered an available slot while enrollment_id is NULL.
     * Booking binds that slot to one active enrollment owned by the student.
     */
    public function book(Request $request, int $id): JsonResponse
    {
        $student = $request->user()?->student;

        if (! $student) {
            return response()->json([
                'status' => false,
                'message' => 'Student account not found.',
                'code' => 'STUDENT_PROFILE_NOT_FOUND',
            ], 403);
        }

        $validated = $request->validate([
            'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
        ]);

        $result = DB::transaction(function () use ($student, $validated, $id) {
            $session = Session::query()->lockForUpdate()->findOrFail($id);

            if ($session->type !== 'INDIVIDUAL') {
                return ['error' => ['This endpoint books individual sessions only.', 'SESSION_TYPE_NOT_BOOKABLE', 422]];
            }

            if ($session->status !== 'scheduled') {
                return ['error' => ['Only scheduled sessions can be booked.', 'SESSION_NOT_AVAILABLE', 409]];
            }

            if ($session->starts_at !== null && $session->starts_at->lte(now())) {
                return ['error' => ['Past or started sessions cannot be booked.', 'SESSION_ALREADY_STARTED', 409]];
            }

            if ($session->enrollment_id !== null) {
                $existing = Enrollment::find($session->enrollment_id);
                $code = $existing && (int) $existing->student_id === (int) $student->id
                    ? 'ALREADY_BOOKED_BY_STUDENT'
                    : 'SESSION_ALREADY_BOOKED';

                return ['error' => ['This session is already booked.', $code, 409]];
            }

            $query = Enrollment::query()
                ->where('student_id', $student->id)
                ->where('teacher_id', $session->teacher_id)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });

            if (! empty($validated['enrollment_id'])) {
                $enrollment = (clone $query)->whereKey($validated['enrollment_id'])->first();
                if (! $enrollment) {
                    return ['error' => ['The selected enrollment is not an active enrollment for this student and teacher.', 'INVALID_ENROLLMENT', 422]];
                }
            } else {
                $matches = $query->orderBy('id')->limit(2)->get();
                if ($matches->isEmpty()) {
                    return ['error' => ['No active enrollment is available for this teacher.', 'NO_ACTIVE_ENROLLMENT', 422]];
                }
                if ($matches->count() > 1) {
                    return ['error' => ['More than one active enrollment matches this teacher. Send enrollment_id in the request body.', 'ENROLLMENT_SELECTION_REQUIRED', 422]];
                }
                $enrollment = $matches->first();
            }

            $hasConflict = Session::query()
                ->where('id', '!=', $session->id)
                ->where('enrollment_id', $enrollment->id)
                ->whereIn('status', ['scheduled', 'ongoing'])
                ->where('starts_at', '<', $session->ends_at)
                ->where('ends_at', '>', $session->starts_at)
                ->exists();

            if ($hasConflict) {
                return ['error' => ['The student already has another session that overlaps this time.', 'SESSION_TIME_CONFLICT', 409]];
            }

            $session->update([
                'enrollment_id' => $enrollment->id,
                'capacity' => 1,
            ]);

            return ['session' => $session->fresh()->load(['teacher.user:id,name,email', 'enrollment'])];
        });

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];
            return response()->json(['status' => false, 'message' => $message, 'code' => $code], $status);
        }

        return $this->success($result['session'], 'Session booked successfully.');
    }

    /**
     * Student cancellation endpoint from the SDD:
     * DELETE /api/v1/sessions/{id}/booking
     *
     * Cancelling a booking releases the INDIVIDUAL slot by clearing enrollment_id;
     * it does not delete the session and does not cancel the teacher's slot.
     */
    public function cancelBooking(Request $request, int $id): JsonResponse
    {
        $student = $request->user()?->student;

        if (! $student) {
            return response()->json([
                'status' => false,
                'message' => 'Student account not found.',
                'code' => 'STUDENT_PROFILE_NOT_FOUND',
            ], 403);
        }

        $result = DB::transaction(function () use ($student, $id) {
            $session = Session::query()->lockForUpdate()->findOrFail($id);

            if ($session->type !== 'INDIVIDUAL' || $session->enrollment_id === null) {
                return ['error' => ['This session has no student booking to cancel.', 'BOOKING_NOT_FOUND', 404]];
            }

            $enrollment = Enrollment::find($session->enrollment_id);
            if (! $enrollment || (int) $enrollment->student_id !== (int) $student->id) {
                return ['error' => ['This booking does not belong to the authenticated student.', 'BOOKING_FORBIDDEN', 403]];
            }

            if ($session->status !== 'scheduled') {
                return ['error' => ['Only scheduled bookings can be cancelled.', 'BOOKING_CANNOT_BE_CANCELLED', 409]];
            }

            if ($session->starts_at !== null && $session->starts_at->lte(now())) {
                return ['error' => ['A started or past session cannot be cancelled.', 'BOOKING_ALREADY_STARTED', 409]];
            }

            $session->update(['enrollment_id' => null]);

            return ['session' => $session->fresh()];
        });

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];
            return response()->json(['status' => false, 'message' => $message, 'code' => $code], $status);
        }

        return $this->success($result['session'], 'Session booking cancelled successfully.');
    }

    /**
     * Teacher cancellation endpoint:
     * POST /api/v1/sessions/{session}/cancel
     *
     * This cancels the SESSION itself, unlike cancelBooking() which only releases
     * a student's INDIVIDUAL booking. The enrollment/group links are preserved for
     * audit/history; the session status becomes "cancelled".
     */
    public function cancel(Request $request, Session $session): JsonResponse
    {
        $teacher = $request->user()?->teacher;

        if (! $teacher) {
            return response()->json([
                'status' => false,
                'message' => 'Teacher account not found.',
                'code' => 'TEACHER_PROFILE_NOT_FOUND',
            ], 403);
        }

        $result = DB::transaction(function () use ($teacher, $session) {
            $session = Session::query()
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ((int) $session->teacher_id !== (int) $teacher->id) {
                return ['error' => [
                    'This session does not belong to the authenticated teacher.',
                    'SESSION_FORBIDDEN',
                    403,
                ]];
            }

            if ($session->status === 'cancelled') {
                return ['session' => $session->fresh(), 'already_cancelled' => true];
            }

            if ($session->status === 'completed') {
                return ['error' => [
                    'A completed session cannot be cancelled.',
                    'SESSION_ALREADY_COMPLETED',
                    409,
                ]];
            }

            if ($session->status === 'ongoing') {
                return ['error' => [
                    'An ongoing session cannot be cancelled.',
                    'SESSION_ALREADY_STARTED',
                    409,
                ]];
            }

            if ($session->status !== 'scheduled') {
                return ['error' => [
                    'Only scheduled sessions can be cancelled.',
                    'SESSION_CANNOT_BE_CANCELLED',
                    409,
                ]];
            }

            if ($session->starts_at !== null && $session->starts_at->lte(now())) {
                return ['error' => [
                    'A started or past session cannot be cancelled.',
                    'SESSION_ALREADY_STARTED',
                    409,
                ]];
            }

            $session->update([
                'status' => 'cancelled',
            ]);

            return ['session' => $session->fresh(), 'already_cancelled' => false];
        });

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

    public function show(Session $session): JsonResponse
    {
        return $this->success($session);
    }

    public function update(Request $request, Session $session): JsonResponse
    {
        $session->update($request->validate([
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'availability_id' => ['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id'],
            'type' => ['sometimes', 'string', 'max:50'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'enrollment_id' => ['sometimes', 'nullable', 'integer', 'exists:enrollments,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($session->fresh(), 'Session updated successfully.');
    }

    public function destroy(Session $session): JsonResponse
    {
        $session->delete();

        return $this->success(message: 'Session deleted successfully.');
    }
}
