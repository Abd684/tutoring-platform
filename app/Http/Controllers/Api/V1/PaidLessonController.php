<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaidBooking;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Models\PaymentSetting;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Services\AvailabilityBooking;
use App\Services\PaidLessonBookings;
use App\Services\ShamCashPayments;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PaidLessonController extends ApiController
{
    public function checkout(Request $request)
    {
        $data = $request->validate(['service_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at']]);
        $settings = PaymentSetting::findOrFail(1);
        if (! $settings->account_id || ! $settings->qr_path) {
            app(ShamCashPayments::class)->fail('PAYMENT_NOT_CONFIGURED', 'Sham Cash payment is not configured.', 503);
        }
        $availability = TeacherAvailability::with('teacher.user')->findOrFail($data['service_id']);
        $start = Carbon::parse($data['starts_at'])->setTimezone(config('app.timezone'));
        $end = Carbon::parse($data['ends_at'])->setTimezone(config('app.timezone'));
        $bookings = app(PaidLessonBookings::class);
        $bookings->window($availability, $start, $end);
        if ($bookings->conflicts($availability->teacher_id, $request->user()->student->id, $start, $end)) {
            app(ShamCashPayments::class)->fail('SLOT_UNAVAILABLE', 'This interval is already occupied.');
        }
        $checkout = PaymentCheckout::create(['id' => (string) Str::uuid(), 'user_id' => $request->user()->id,
            'service_type' => 'lesson_booking', 'service_id' => $availability->id,
            'amount' => $availability->price, 'currency' => 'SYP', 'account_id' => $settings->account_id, 'qr_path' => $settings->qr_path,
            'booking_starts_at' => $start, 'booking_ends_at' => $end,
            'expires_at' => now()->addMinutes(config('payments.checkout_minutes'))]);

        return $this->success(['checkout_id' => $checkout->id, 'internal_reference' => $checkout->id,
            'amount' => $checkout->amount, 'currency' => 'SYP', 'account_id' => $checkout->account_id,
            'qr_url' => route('payments.checkout.qr', $checkout), 'expires_at' => $checkout->expires_at->toIso8601String(),
            'service' => ['type' => 'lesson_booking', 'id' => $availability->id, 'title' => 'درس مدفوع',
                'teacher' => ['id' => $availability->teacher_id, 'name' => $availability->teacher->user->name],
                'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]], status: 201);
    }

    public function slots(Request $request, Teacher $teacher)
    {
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        $data = $request->validate(['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date']]);
        $from = Carbon::parse($data['from'] ?? now())->max(now());
        $to = isset($data['to']) ? Carbon::parse($data['to']) : $from->copy()->addDays(7);
        abort_unless($to->gt($from) && $to->lte($from->copy()->addDays(31)), 422, 'Choose a period of at most 31 days.');
        $slots = collect();
        foreach (TeacherAvailability::where('teacher_id', $teacher->id)->where('status', 'active')->where('price', '>', 0)->get() as $availability) {
            for ($day = $from->copy()->setTimezone($availability->timezone)->startOfDay(); $day->lt($to); $day->addDay()) {
                $start = Carbon::parse($day->toDateString().' '.$availability->start_time, $availability->timezone)->setTimezone(config('app.timezone'));
                $end = Carbon::parse($day->toDateString().' '.$availability->end_time, $availability->timezone)->setTimezone(config('app.timezone'));
                if ($start->lt($from) || $end->gt($to) || $start->lte(now()) || ! app(AvailabilityBooking::class)->contains($availability, $start, $end)
                    || app(PaidLessonBookings::class)->conflicts($teacher->id, $request->user()->student->id, $start, $end)) {
                    continue;
                }
                $slots->push(['availability_id' => $availability->id, 'teacher_id' => $teacher->id, 'amount' => $availability->price,
                    'currency' => 'SYP', 'starts_at' => $start->utc()->toIso8601String(), 'ends_at' => $end->utc()->toIso8601String()]);
            }
        }
        $slots = $slots->sortBy('starts_at')->values();
        $page = max(1, $request->integer('page', 1));
        $perPage = $this->perPage($request);

        return $this->paginated(new LengthAwarePaginator($slots->forPage($page, $perPage)->values(), $slots->count(), $perPage, $page));
    }

    /** Called only after the shared endpoint validates the multipart proof and fields. */
    public function submit(Request $request, PaymentCheckout $seed)
    {
        $path = null;
        $service = app(ShamCashPayments::class);
        try {
            [$payment, $created] = DB::transaction(function () use ($request, $seed, $service, &$path) {
                $teacherId = TeacherAvailability::findOrFail($seed->service_id)->teacher_id;
                Teacher::whereKey($teacherId)->lockForUpdate()->firstOrFail();
                $student = Student::where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
                $checkout = PaymentCheckout::whereKey($seed->id)->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
                if ($checkout->payment_id) {
                    $payment = Payment::findOrFail($checkout->payment_id);
                    if ($payment->provider_reference !== $request->input('provider_reference')) {
                        $service->fail('CHECKOUT_CONSUMED', 'This checkout was already submitted.');
                    }

                    return [$payment, false];
                }
                if ($checkout->expires_at->lte(now())) {
                    $service->fail('CHECKOUT_EXPIRED', 'Please start a new checkout.');
                }
                $availability = TeacherAvailability::whereKey($checkout->service_id)->lockForUpdate()->firstOrFail();
                $bookings = app(PaidLessonBookings::class);
                $bookings->window($availability, $checkout->booking_starts_at, $checkout->booking_ends_at);
                if ($availability->price !== $checkout->amount) {
                    $service->fail('PRICE_CHANGED', 'The lesson price changed. Please start a new checkout.');
                }
                if ($bookings->conflicts($teacherId, $student->id, $checkout->booking_starts_at, $checkout->booking_ends_at)) {
                    $service->fail('SLOT_UNAVAILABLE', 'This interval is already occupied.');
                }
                if (Payment::where('provider_reference', $request->input('provider_reference'))->exists()) {
                    $service->fail('TRANSFER_ALREADY_USED', 'This transfer reference was already submitted.');
                }
                $booking = PaidBooking::create(['student_id' => $student->id, 'teacher_id' => $teacherId, 'availability_id' => $availability->id,
                    'status' => 'HELD', 'starts_at' => $checkout->booking_starts_at, 'ends_at' => $checkout->booking_ends_at,
                    'hold_expires_at' => now()->addHours(config('payments.booking_hold_hours'))]);
                $enrollment = $booking->enrollment()->create(['student_id' => $student->id, 'teacher_id' => $teacherId,
                    'price' => $checkout->amount, 'status' => 'pending']);
                $path = $request->file('proof')->store('payment-proofs/'.$student->id, 'local');
                abort_unless($path, 500, 'Unable to store payment proof.');
                $payment = Payment::create(['payer_id' => $request->user()->id, 'payable_type' => $enrollment->getMorphClass(),
                    'payable_id' => $enrollment->id, 'amount' => $checkout->amount, 'currency' => 'SYP', 'provider' => 'sham_cash',
                    'provider_reference' => $request->input('provider_reference'), 'sham_transfer_reference' => $request->input('provider_reference'),
                    'internal_reference' => $checkout->id, 'proof_path' => $path, 'proof_mime' => $request->file('proof')->getMimeType(),
                    'status' => 'pending', 'submitted_at' => now(), 'service_snapshot' => ['type' => 'lesson_booking',
                        'id' => $availability->id, 'booking_id' => $booking->id, 'title' => 'درس مدفوع', 'account_id' => $checkout->account_id,
                        'teacher' => ['id' => $teacherId, 'name' => $availability->teacher->user->name],
                        'starts_at' => $checkout->booking_starts_at->toIso8601String(), 'ends_at' => $checkout->booking_ends_at->toIso8601String()]]);
                $booking->update(['payment_id' => $payment->id]);
                $checkout->update(['payment_id' => $payment->id]);

                return [$payment, true];
            });
        } catch (Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                $service->fail('TRANSFER_ALREADY_USED', 'This transfer reference was already submitted.');
            }
            throw $error;
        }

        return $this->success($service->payload($payment), 'Payment submitted for administrator review.', $created ? 201 : 200);
    }

    public function review(Request $request, Payment $seed)
    {
        $data = $request->validate(['decision' => ['required', 'in:confirm,reject'],
            'rejection_reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000', 'regex:/\S/u']]);

        return DB::transaction(function () use ($request, $seed, $data) {
            $bookings = app(PaidLessonBookings::class);
            [$payment, $enrollment, $booking] = $bookings->lockPayment($seed);
            abort_unless($booking && (int) $booking->payment_id === (int) $payment->id && $payment->provider === 'sham_cash', 422);
            if ($payment->status !== 'pending' || $enrollment->status !== 'pending') {
                return $this->businessError('This payment already has a decision.', 409);
            }
            if ($payment->amount !== $enrollment->price || (int) $payment->payer_id !== (int) $enrollment->student->user_id) {
                return $this->businessError('Payment does not match the booking.', 409);
            }
            $confirmed = $data['decision'] === 'confirm';
            $payment->update(['status' => $confirmed ? 'succeeded' : 'failed', 'paid_at' => $confirmed ? now() : null,
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'rejection_reason' => $confirmed ? null : trim($data['rejection_reason'])]);
            if ($confirmed) {
                $bookings->fulfill($booking, $enrollment);
            } else {
                $booking->update(['status' => 'REJECTED']);
                $enrollment->update(['status' => 'cancelled']);
            }
            $bookings->audit('booking.payment_'.$data['decision'], $booking, $request->user()->id, ['payment_id' => $payment->id, 'rejection_reason' => $payment->rejection_reason]);

            return $this->success(['payment' => app(ShamCashPayments::class)->payload($payment->fresh()),
                'booking' => $booking->fresh(), 'enrollment' => $enrollment->fresh()], 'Payment review completed.');
        });
    }

    public function index(Request $request)
    {
        $paginator = PaidBooking::where('student_id', $request->user()->student->id)->with('payment')->orderByDesc('id')->paginate($this->perPage($request));
        $paginator->setCollection($paginator->getCollection()->map(fn ($booking) => $this->payload($booking)));

        return $this->paginated($paginator);
    }

    public function show(PaidBooking $booking)
    {
        $this->authorize('view', $booking);

        return $this->success($this->payload($booking));
    }

    public function reschedule(Request $request, PaidBooking $booking, PaidLessonBookings $bookings)
    {
        abort_unless((int) $booking->student_id === (int) $request->user()->student->id, 404);
        $data = $request->validate(['availability_id' => ['required', 'integer'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at']]);

        return $this->success($this->payload($bookings->reschedule($booking, $data, $request->user()->id)), 'Lesson rescheduled.');
    }

    private function payload(PaidBooking $booking): array
    {
        $payload = $booking->toArray();
        if ($booking->status === 'HELD' && $booking->hold_expires_at->lte(now())) {
            $payload['status'] = 'EXPIRED';
        }
        $payload['payment'] = app(ShamCashPayments::class)->payload($booking->payment);

        return $payload;
    }
}
