<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Teacher;
use App\Services\TeacherCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class TeacherCalendarController extends ApiController
{
    public function index(Request $request, Teacher $teacher, TeacherCalendar $calendar)
    {
        $this->authorize('accessStudent', Enrollment::class);
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'], 'timezone' => ['sometimes', 'timezone'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $timezone = $data['timezone'] ?? 'UTC';
        $from = isset($data['from']) ? Carbon::parse($data['from'], $timezone)->utc() : now()->setTimezone($timezone)->startOfDay()->utc();
        $to = isset($data['to']) ? Carbon::parse($data['to'], $timezone)->utc() : $from->copy()->addDays(7);
        if ($to->lte($from) || $to->gt($from->copy()->addDays(31))) {
            throw ValidationException::withMessages(['to' => ['Choose an exclusive end after from, at most 31 days later.']]);
        }
        $result = $calendar->periods($teacher, $request->user()->student, $from, $to, $timezone);
        $page = $data['page'] ?? 1;
        $perPage = $data['per_page'] ?? 50;
        $all = collect($result['periods']);
        $paginator = new LengthAwarePaginator($all->slice(($page - 1) * $perPage, $perPage)->values(), $all->count(), $perPage, $page);

        return response()->json(['status' => true, 'data' => $paginator->items(), 'meta' => [
            'current_page' => $page, 'per_page' => $perPage, 'total' => $all->count(), 'last_page' => $paginator->lastPage(),
            'teacher_id' => $teacher->id, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => $timezone,
            'has_schedule' => $result['has_schedule'], 'has_eligible_enrollment_in_period' => $result['has_eligible_enrollment_in_period'],
            'booking_contract' => 'availability', 'booking_endpoint' => '/teacher-availabilities/{availability_id}/book',
        ]]);
    }
}
