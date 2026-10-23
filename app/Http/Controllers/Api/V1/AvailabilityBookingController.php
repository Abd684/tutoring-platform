<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Services\AvailabilityBooking;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AvailabilityBookingController extends ApiController
{
    public function index(Request $request, Teacher $teacher, AvailabilityBooking $booking)
    {
        $this->authorize('accessStudent', Enrollment::class);
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date']]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->max(now()) : now();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : $from->copy()->addDays(7);
        abort_unless($to->gt($from) && $to->lte($from->copy()->addDays(31)), 422, 'Choose a period of at most 31 days.');

        return $this->success($booking->slots($teacher, $request->user()->student, $from, $to));
    }

    public function store(Request $request, TeacherAvailability $availability, AvailabilityBooking $booking)
    {
        $this->authorize('book', $availability);
        $data = $request->validate(['enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at']]);

        return $this->success($booking->book($request->user()->student, $availability, $data), 'Session booked successfully.', 201);
    }
}
