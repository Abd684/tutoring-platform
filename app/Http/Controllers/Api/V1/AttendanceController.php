<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attendance;
use App\Models\GroupEnrollment;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    /** POST /api/v1/sessions/{id}/attendance */
    public function recordForSession(Request $request, int $id): JsonResponse
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
            'records' => ['required', 'array', 'min:1'],
            'records.*.enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'records.*.check_in_at' => ['nullable', 'date'],
            'records.*.check_out_at' => ['nullable', 'date'],
            'records.*.note' => ['nullable', 'string'],
        ]);

        $session = Session::findOrFail($id);

        if ((int) $session->teacher_id !== (int) $teacher->id) {
            return response()->json([
                'status' => false,
                'message' => 'This session does not belong to the authenticated teacher.',
                'code' => 'SESSION_FORBIDDEN',
            ], 403);
        }

        if ($session->status === 'cancelled') {
            return response()->json([
                'status' => false,
                'message' => 'Attendance cannot be recorded for a cancelled session.',
                'code' => 'SESSION_CANCELLED',
            ], 422);
        }

        if ($session->type === 'INDIVIDUAL') {
            if ($session->enrollment_id === null) {
                return response()->json([
                    'status' => false,
                    'message' => 'The individual session has no booked enrollment.',
                    'code' => 'SESSION_NOT_BOOKED',
                ], 422);
            }
            $allowedEnrollmentIds = collect([(int) $session->enrollment_id]);
        } elseif ($session->type === 'GROUP') {
            if ($session->group_id === null) {
                return response()->json([
                    'status' => false,
                    'message' => 'The group session has no group assigned.',
                    'code' => 'SESSION_GROUP_MISSING',
                ], 422);
            }

            $allowedEnrollmentIds = GroupEnrollment::query()
                ->where('group_id', $session->group_id)
                ->where('status', 'active')
                ->pluck('enrollment_id')
                ->map(fn ($value) => (int) $value);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Unsupported session type.',
                'code' => 'SESSION_TYPE_INVALID',
            ], 422);
        }

        foreach ($validated['records'] as $record) {
            if (! $allowedEnrollmentIds->contains((int) $record['enrollment_id'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'One of the enrollments does not belong to this session.',
                    'code' => 'ENROLLMENT_NOT_IN_SESSION',
                    'enrollment_id' => (int) $record['enrollment_id'],
                ], 422);
            }

            if (! empty($record['check_in_at']) && ! empty($record['check_out_at'])
                && strtotime($record['check_out_at']) < strtotime($record['check_in_at'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'check_out_at must be after or equal to check_in_at.',
                    'code' => 'INVALID_ATTENDANCE_TIME',
                    'enrollment_id' => (int) $record['enrollment_id'],
                ], 422);
            }
        }

        $attendance = DB::transaction(function () use ($validated, $session) {
            $saved = collect();

            foreach ($validated['records'] as $record) {
                $model = Attendance::updateOrCreate(
                    [
                        'session_id' => $session->id,
                        'enrollment_id' => $record['enrollment_id'],
                    ],
                    [
                        'status' => $record['status'],
                        'check_in_at' => $record['check_in_at'] ?? null,
                        'check_out_at' => $record['check_out_at'] ?? null,
                        'note' => $record['note'] ?? null,
                    ]
                );

                $saved->push($model->fresh()->load('enrollment.student'));
            }

            return $saved;
        });

        return $this->success($attendance, 'Attendance recorded successfully.');
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
