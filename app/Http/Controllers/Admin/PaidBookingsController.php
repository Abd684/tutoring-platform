<?php

namespace App\Http\Controllers\Admin;

use App\Models\PaidBooking;
use App\Models\RefundRequest;
use App\Models\TeacherAvailability;
use App\Services\PaidLessonBookings;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class PaidBookingsController extends AdminController
{
    public function index(Request $request)
    {
        $data = $this->filters($request);
        $request->validate(['booking_id' => ['nullable', 'integer', 'exists:paid_bookings,id']]);
        $query = PaidBooking::with(['student.user', 'teacher.user', 'payment', 'session']);
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(fn ($q) => $q->where('id', ctype_digit($term) ? (int) $term : 0)->orWhereHas('student.user', fn ($q) => $q->where('name', 'like', "%{$term}%"))->orWhereHas('teacher.user', fn ($q) => $q->where('name', 'like', "%{$term}%"))->orWhereHas('payment', fn ($q) => $q->where('provider_reference', 'like', "%{$term}%")));
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['from'])) {
            $query->whereDate('starts_at', '>=', $data['from']);
        }if (! empty($data['to'])) {
            $query->whereDate('starts_at', '<=', $data['to']);
        }
        if ($request->boolean('export')) {
            return $this->csv($query->orderBy('id'), ['الحجز' => 'id', 'الطالب' => 'student.user.name', 'المدرس' => 'teacher.user.name', 'البداية' => 'starts_at', 'النهاية' => 'ends_at', 'الحالة' => 'status', 'مرجع الدفع' => 'payment.provider_reference'], 'paid-bookings');
        }
        $items = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $selected = $request->filled('booking_id') ? PaidBooking::with(['student.user', 'teacher.user', 'payment', 'session', 'enrollment', 'availability'])->findOrFail($request->integer('booking_id')) : null;
        $alternatives = $selected ? TeacherAvailability::where('teacher_id', $selected->teacher_id)->where('status', 'active')->where('price', $selected->payment?->amount)->where(fn ($q) => $q->where('recurrence_type', 'weekly')->orWhereDate('date', '>=', today()))->get() : collect();
        $canReschedule = $selected && $selected->payment?->status === 'succeeded' && ($selected->status === 'NEEDS_RESCHEDULE' || ($selected->status === 'CANCELLED' && $selected->cancelled_by_role === 'teacher')) && ! RefundRequest::where('active_payment_id', $selected->payment_id)->exists();
        $stats = ['total' => PaidBooking::count(), 'held' => PaidBooking::holding()->count(), 'confirmed' => PaidBooking::where('status', 'CONFIRMED')->count(), 'reschedule' => PaidBooking::where('status', 'NEEDS_RESCHEDULE')->count()];

        return view('admin.bookings.index', compact('items', 'selected', 'alternatives', 'canReschedule', 'stats'));
    }

    public function reschedule(Request $request, PaidBooking $booking)
    {
        $data = $request->validate(['availability_id' => ['required', 'integer', 'exists:teacher_availabilities,id'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at']]);
        try {
            app(PaidLessonBookings::class)->reschedule($booking, $data, $request->user()->id);
        } catch (HttpResponseException $error) {
            return $this->apiResult($error->getResponse(),'تم اعتماد الموعد البديل.');
        }

        return back()->with('success','تم اعتماد الموعد البديل عبر قواعد الحجز المدفوع.');
    }
}
