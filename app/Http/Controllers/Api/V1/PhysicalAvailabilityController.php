<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Services\AvailabilityBooking;
use App\Services\PaidLessonBookings;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PhysicalAvailabilityController extends ApiController
{
    public function index(Request $r, Teacher $teacher)
    {
        $d = $r->validate([
            'teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'offer_id' => ['prohibited'],
        ]);
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        $course = TeacherSubject::whereKey($d['teacher_subject_id'])->where('teacher_id', $teacher->id)
            ->where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active'))->firstOrFail();
        if ($course->individual_session_price === null) {
            return response()->json(['status' => false, 'code' => 'INDIVIDUAL_PRICE_REQUIRED', 'message' => 'لم يحدد الأستاذ سعر الجلسة الفردية لهذه المادة.'], 409);
        }
        $slots = [];
        foreach (TeacherAvailability::where('teacher_id', $teacher->id)->where('status', 'active')->orderBy('start_time')->orderBy('id')->get() as $availability) {
            $start = Carbon::parse($d['date'].' '.$availability->start_time, $availability->timezone);
            $end = Carbon::parse($d['date'].' '.$availability->end_time, $availability->timezone);
            if (! app(AvailabilityBooking::class)->contains($availability, $start, $end)) {
                continue;
            }
            $busy = app(PaidLessonBookings::class)->conflicts($teacher->id, $r->user()->student->id, $start, $end);
            $slots[] = ['availability_id' => $availability->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(),
                'timezone' => $availability->timezone, 'status' => $start->lte(now()) ? 'unavailable' : ($busy ? 'booked' : 'available')];
        }

        return $this->success(['date' => $d['date'], 'teacher_subject_id' => $course->id, 'session_price' => $course->individual_session_price,
            'currency' => $course->individual_currency, 'teaching_scope_type' => 'subject', 'teaching_scope_id' => $course->id, 'slots' => $slots]);
    }
}
