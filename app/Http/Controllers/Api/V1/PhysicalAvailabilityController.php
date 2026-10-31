<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\IndividualOffer;
use App\Models\Session;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Services\AvailabilityBooking;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PhysicalAvailabilityController extends ApiController
{
    public function index(Request $r, Teacher $teacher)
    {
        $d = $r->validate(['offer_id' => ['required', 'integer', 'exists:individual_offers,id'], 'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        IndividualOffer::whereKey($d['offer_id'])->where('status', 'active')->whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $teacher->id)->where('status', 'active'))->firstOrFail();
        $slots = [];
        foreach (TeacherAvailability::where('teacher_id', $teacher->id)->where('status', 'active')->get() as $a) {
            $start = Carbon::parse($d['date'].' '.$a->start_time, $a->timezone);
            $end = Carbon::parse($d['date'].' '.$a->end_time, $a->timezone);
            if (! app(AvailabilityBooking::class)->contains($a, $start, $end)) {
                continue;
            }
            $busy = Session::whereIn('status', ['scheduled', 'ongoing'])->where('starts_at', '<', $end->copy()->setTimezone(config('app.timezone')))->where('ends_at', '>', $start->copy()->setTimezone(config('app.timezone')))->where(fn ($q) => $q->where('teacher_id', $teacher->id)->orWhere(fn ($q) => $q->forStudent($r->user()->student->id)))->exists();
            $slots[] = ['availability_id' => $a->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'timezone' => $a->timezone, 'status' => $start->lte(now()) ? 'unavailable' : ($busy ? 'booked' : 'available')];
        }

        return $this->success(['date' => $d['date'], 'offer_id' => (int) $d['offer_id'], 'slots' => $slots]);
    }
}
