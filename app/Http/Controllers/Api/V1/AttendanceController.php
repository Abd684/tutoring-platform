<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attendance;
use App\Models\Session;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);

        $query = Attendance::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Attendance::class);

        $attendance = Attendance::create($request->validate([
            'session_id' => ['required', 'integer', 'exists:sessions,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
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
        $session = Session::findOrFail($id);
        $this->authorize('recordAttendance', $session);
        $data = $request->validate(['records' => ['required', 'array', 'min:1'],
            'records.*.student_id' => ['required', 'distinct', 'integer', 'exists:students,id'],
            'records.*.enrollment_id' => ['prohibited'],
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])],
            'records.*.check_in_at' => ['nullable', 'date'], 'records.*.check_out_at' => ['nullable', 'date'],
            'records.*.note' => ['nullable', 'string', 'max:10000']]);

        return DB::transaction(function () use ($session, $data) {
            Teacher::whereKey($session->teacher_id)->lockForUpdate()->firstOrFail();
            $session = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if($session->status === 'cancelled', 422, 'Attendance cannot be recorded for a cancelled session.');
            $allowed = $session->participantIds();
            foreach ($data['records'] as $record) {
                abort_unless(in_array((int) $record['student_id'], $allowed, true), 422, 'Student does not belong to this session.');
                abort_if(! empty($record['check_in_at']) && ! empty($record['check_out_at'])
                    && strtotime($record['check_out_at']) < strtotime($record['check_in_at']), 422, 'Check-out must follow check-in.');
            }
            $saved = collect();
            foreach ($data['records'] as $record) {
                $saved->push(Attendance::updateOrCreate(['session_id' => $session->id, 'student_id' => $record['student_id']],
                    ['status' => $record['status'], 'check_in_at' => $record['check_in_at'] ?? null,
                        'check_out_at' => $record['check_out_at'] ?? null, 'note' => $record['note'] ?? null])->load('student'));
            }

            return $this->success($saved, 'Attendance recorded successfully.');
        });
    }

    public function show(Attendance $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        return $this->success($attendance);
    }

    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        $this->authorize('update', $attendance);

        $attendance->update($request->validate([
            'session_id' => ['sometimes', 'integer', 'exists:sessions,id'],
            'student_id' => ['sometimes', 'integer', 'exists:students,id'],
            'status' => ['sometimes', 'string', 'max:50'],
            'check_in_at' => ['sometimes', 'nullable', 'date'],
            'check_out_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:check_in_at'],
            'note' => ['sometimes', 'nullable', 'string'],
        ]));

        return $this->success($attendance->fresh(), 'Attendance updated successfully.');
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $this->authorize('delete', $attendance);

        $attendance->delete();

        return $this->success(message: 'Attendance deleted successfully.');
    }

    public function percentage(Request $request): JsonResponse
    {
        $this->authorize('accessStudent', Attendance::class);

        $student = $request->user()->student;

        if (! $student) {
            return response()->json([
                'message' => 'Student account not found.',
            ], 404);
        }

        $query = Attendance::query()
            ->where('student_id', $student->id)
            ->whereHas('session', function ($query) {
                $query->where('ends_at', '<=', now());
            });

        $totalSessions = (clone $query)->count();

        $attendedSessions = (clone $query)
            ->whereIn('status', ['present', 'late'])
            ->count();

        $absentSessions = (clone $query)
            ->where('status', 'absent')
            ->count();

        $percentage = $totalSessions > 0
            ? round(($attendedSessions / $totalSessions) * 100, 2)
            : 0;

        return response()->json([
            'data' => [
                'attendance_percentage' => $percentage,
                'attended_sessions' => $attendedSessions,
                'absent_sessions' => $absentSessions,
                'total_sessions' => $totalSessions,
            ],
        ]);
    }
}
