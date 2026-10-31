<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Session;
use App\Models\Teacher;
use App\Services\IndividualBooking;
use Illuminate\Http\Request;

class IndividualBookingController extends ApiController
{
    public function store(Request $request, Teacher $teacher, IndividualBooking $booking)
    {
        $this->authorize('book', Session::class);
        $data = $request->validate([
            'availability_id' => ['required', 'integer', 'exists:teacher_availabilities,id'],
            'individual_id' => ['required', 'integer', 'exists:individuals,id'], 'enrollment_id' => ['prohibited'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        return $this->success($booking->book($request->user()->student, $teacher, $data), 'Session booked successfully.', 201);
    }
}
