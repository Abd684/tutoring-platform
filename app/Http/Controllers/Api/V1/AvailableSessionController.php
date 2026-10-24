<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaidBooking;
use App\Models\Session;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Services\IndividualBooking;
use App\Services\SessionEligibility;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class AvailableSessionController extends ApiController
{
    public function index(Request $request, Teacher $teacher)
    {
        $this->authorize('book', Session::class);
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        $filters = $request->validate(['from' => ['sometimes', 'date'], 'to' => array_merge(['sometimes', 'date'], $request->filled('from') ? ['after_or_equal:from'] : [])]);
        $from = Carbon::parse($filters['from'] ?? now())->max(now())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? $from->copy()->addDays(14));
        abort_if($to->gt($from->copy()->addDays(31)), 422, 'Maximum availability range is 31 days.');
        $slots = collect();
        $student = $request->user()->student;
        $eligibility = app(SessionEligibility::class);
        foreach (TeacherAvailability::where('teacher_id', $teacher->id)->where('status', 'active')->get() as $availability) {
            for ($day = $from->copy()->setTimezone($availability->timezone)->startOfDay(); $day->lte($to); $day->addDay()) {
                $start = Carbon::parse($day->toDateString().' '.$availability->start_time, $availability->timezone);
                $end = Carbon::parse($day->toDateString().' '.$availability->end_time, $availability->timezone);
                $start = $start->max(now()->addSecond())->max(Carbon::parse($filters['from'] ?? now()));
                $end = $end->min($to);
                if (! app(IndividualBooking::class)->contains($availability, $start, $end)) {
                    continue;
                }
                // Remove occupied intervals; the remaining windows can be booked without empty sessions.
                $windows = [[$start, $end]];
                $busy = Session::whereIn('status', ['scheduled', 'ongoing'])
                    ->where('starts_at', '<', $end->copy()->setTimezone(config('app.timezone')))
                    ->where('ends_at', '>', $start->copy()->setTimezone(config('app.timezone')))
                    ->where(fn ($q) => $q->where('teacher_id', $teacher->id)
                        ->orWhereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))
                        ->orWhereHas('group.groupEnrollments', fn ($q) => $q->where('status', 'active')->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))))->get();
                $busy = $busy->concat(PaidBooking::holding()->where('starts_at', '<', $end->copy()->setTimezone(config('app.timezone')))->where('ends_at', '>', $start->copy()->setTimezone(config('app.timezone')))
                    ->where(fn ($q) => $q->where('teacher_id', $teacher->id)->orWhere('student_id', $student->id))->get());
                foreach ($busy as $session) {
                    $next = [];
                    foreach ($windows as [$left, $right]) {
                        if ($session->ends_at->lte($left) || $session->starts_at->gte($right)) {
                            $next[] = [$left, $right];

                            continue;
                        }
                        if ($session->starts_at->gt($left)) {
                            $next[] = [$left, $session->starts_at];
                        }
                        if ($session->ends_at->lt($right)) {
                            $next[] = [$session->ends_at, $right];
                        }
                    }
                    $windows = $next;
                }
                foreach ($windows as [$left, $right]) {
                    if ($left->lt(Carbon::parse($filters['from'] ?? now())) || $left->gt($to)) {
                        continue;
                    }
                    $candidate = new Session(['teacher_id' => $teacher->id, 'starts_at' => $left, 'ends_at' => $right]);
                    $ids = $eligibility->enrollments($student->id, $candidate)->orderBy('id')->pluck('id');
                    if ($ids->isNotEmpty()) {
                        $slots->push(['availability_id' => $availability->id, 'teacher_id' => $teacher->id,
                            'starts_at' => $left->copy()->utc()->toIso8601String(), 'ends_at' => $right->copy()->utc()->toIso8601String(),
                            'eligible_enrollment_ids' => $ids]);
                    }
                }
            }
        }
        $slots = $slots->sortBy('starts_at')->values();
        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request);

        return $this->paginated(new LengthAwarePaginator($slots->forPage($page, $perPage)->values(), $slots->count(), $perPage, $page, ['path' => $request->url()]));
    }
}
