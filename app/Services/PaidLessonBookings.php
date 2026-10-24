<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\PaidBooking;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaidLessonBookings
{
    public function window(TeacherAvailability $availability, Carbon $start, Carbon $end, bool $requirePrice = true): void
    {
        $local = $start->copy()->setTimezone($availability->timezone);
        $fullStart = Carbon::parse($local->toDateString().' '.$availability->start_time, $availability->timezone);
        $fullEnd = Carbon::parse($local->toDateString().' '.$availability->end_time, $availability->timezone);
        if ($start->lte(now()) || ! app(AvailabilityBooking::class)->contains($availability, $start, $end)
            || ! $start->eq($fullStart) || ! $end->eq($fullEnd)
            || $availability->teacher?->status !== 'active' || $availability->teacher?->user?->status !== 'active') {
            app(ShamCashPayments::class)->fail('SLOT_UNAVAILABLE', 'Choose a future, complete interval from active teacher availability.');
        }
        if ($requirePrice && ($availability->price === null || (float) $availability->price <= 0)) {
            app(ShamCashPayments::class)->fail('PRICE_NOT_SET', 'This availability has no paid lesson price.');
        }
    }

    public function holdConflicts(int $teacherId, array $studentIds, $start, $end, ?int $except = null): bool
    {
        $start = Carbon::parse($start)->setTimezone(config('app.timezone'));
        $end = Carbon::parse($end)->setTimezone(config('app.timezone'));

        return PaidBooking::holding()->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->where(fn ($q) => $q->where('teacher_id', $teacherId)->orWhereIn('student_id', $studentIds))->exists();
    }

    public function conflicts(int $teacherId, int $studentId, $start, $end, ?int $exceptBooking = null, ?int $exceptSession = null): bool
    {
        $start = Carbon::parse($start)->setTimezone(config('app.timezone'));
        $end = Carbon::parse($end)->setTimezone(config('app.timezone'));

        if ($this->holdConflicts($teacherId, [$studentId], $start, $end, $exceptBooking)) {
            return true;
        }

        return Session::whereIn('status', ['scheduled', 'ongoing'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($exceptSession, fn ($q) => $q->whereKeyNot($exceptSession))
            ->where(fn ($q) => $q->where('teacher_id', $teacherId)
                ->orWhereHas('enrollment', fn ($q) => $q->where('student_id', $studentId))
                ->orWhereHas('group.groupEnrollments', fn ($q) => $q->where('status', 'active')->whereHas('enrollment', fn ($q) => $q->where('student_id', $studentId))))->exists();
    }

    /** Must run inside a transaction. All paid schedule writers use this lock order. */
    public function lockPayment(Payment $original): array
    {
        abort_unless($original->payable_type === (new Enrollment)->getMorphClass(), 422);
        $seed = Enrollment::findOrFail($original->payable_id);
        $booking = $seed->enrollable_type === 'paid_booking' ? PaidBooking::findOrFail($seed->enrollable_id) : null;
        Teacher::whereKey($seed->teacher_id)->lockForUpdate()->firstOrFail();
        Student::whereKey($seed->student_id)->lockForUpdate()->firstOrFail();
        $enrollment = Enrollment::whereKey($seed->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::whereKey($original->id)->lockForUpdate()->firstOrFail();
        $booking = $booking ? PaidBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail() : null;

        return [$payment, $enrollment, $booking];
    }

    /** Financial approval can succeed while fulfillment needs an alternative slot. */
    public function fulfill(PaidBooking $booking, Enrollment $enrollment): void
    {
        $availability = TeacherAvailability::whereKey($booking->availability_id)->lockForUpdate()->firstOrFail();
        $available = $booking->starts_at->isFuture() && $availability->status === 'active'
            && $availability->teacher?->status === 'active' && $availability->teacher?->user?->status === 'active'
            && $booking->student?->status === 'active' && $booking->student?->user?->status === 'active'
            && app(AvailabilityBooking::class)->contains($availability, $booking->starts_at, $booking->ends_at)
            && ! $this->conflicts($booking->teacher_id, $booking->student_id, $booking->starts_at, $booking->ends_at, $booking->id);
        if (! $available) {
            $booking->update(['status' => 'NEEDS_RESCHEDULE']);

            return;
        }
        $enrollment->update(['status' => 'active', 'starts_at' => $booking->starts_at, 'expires_at' => $booking->ends_at]);
        $session = Session::create(['teacher_id' => $booking->teacher_id, 'availability_id' => $booking->availability_id,
            'type' => 'INDIVIDUAL', 'enrollment_id' => $enrollment->id, 'group_id' => null,
            'starts_at' => $booking->starts_at, 'ends_at' => $booking->ends_at, 'capacity' => 1, 'status' => 'scheduled']);
        $booking->update(['status' => 'CONFIRMED', 'session_id' => $session->id, 'cancelled_by_role' => null]);
    }

    public function reschedule(PaidBooking $seed, array $data, int $userId): PaidBooking
    {
        return DB::transaction(function () use ($seed, $data, $userId) {
            [$payment, $enrollment, $booking] = $this->lockPayment($seed->payment);
            $allowed = $booking->status === 'NEEDS_RESCHEDULE' || ($booking->status === 'CANCELLED' && $booking->cancelled_by_role === 'teacher');
            if ($payment->status !== 'succeeded' || ! $allowed || RefundRequest::where('active_payment_id', $payment->id)->exists()) {
                app(ShamCashPayments::class)->fail('RESCHEDULE_NOT_ALLOWED', 'Only approved, unrefunded lessons needing an alternative may be rescheduled.');
            }
            $availability = TeacherAvailability::whereKey($data['availability_id'])->where('teacher_id', $booking->teacher_id)->lockForUpdate()->firstOrFail();
            $start = Carbon::parse($data['starts_at'])->setTimezone(config('app.timezone'));
            $end = Carbon::parse($data['ends_at'])->setTimezone(config('app.timezone'));
            $this->window($availability, $start, $end);
            if ($availability->price !== $payment->amount) {
                app(ShamCashPayments::class)->fail('PRICE_MISMATCH', 'Choose an alternative at the original paid price.');
            }
            if ($this->conflicts($booking->teacher_id, $booking->student_id, $start, $end, $booking->id)) {
                app(ShamCashPayments::class)->fail('SLOT_UNAVAILABLE', 'The alternative interval is already occupied.');
            }
            $old = $booking->only(['availability_id', 'starts_at', 'ends_at', 'session_id']);
            $booking->update(['availability_id' => $availability->id, 'starts_at' => $start, 'ends_at' => $end, 'session_id' => null]);
            $this->fulfill($booking, $enrollment);
            if ($booking->status !== 'CONFIRMED') {
                app(ShamCashPayments::class)->fail('SLOT_UNAVAILABLE', 'The alternative interval cannot be confirmed.');
            }
            $this->audit('booking.rescheduled', $booking, $userId, ['previous' => $old]);

            return $booking->fresh();
        });
    }

    public function expire(PaidBooking $seed): bool
    {
        return DB::transaction(function () use ($seed) {
            [, , $booking] = $this->lockPayment($seed->payment);
            if ($booking->status !== 'HELD' || $booking->hold_expires_at->isFuture()) {
                return false;
            }
            $booking->update(['status' => 'EXPIRED']);
            $this->audit('booking.hold_expired', $booking, null);

            return true;
        });
    }

    public function cancelSession(Session $seed, string $role, int $userId): Session
    {
        return DB::transaction(function () use ($seed, $role, $userId) {
            $seedBooking = PaidBooking::where('session_id', $seed->id)->firstOrFail();
            [, $enrollment, $booking] = $this->lockPayment($seedBooking->payment);
            $session = Session::whereKey($seed->id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'cancelled') {
                return $session;
            }
            abort_unless($session->status === 'scheduled' && $session->starts_at->isFuture(), 409, 'This lesson can no longer be cancelled.');
            $session->update(['status' => 'cancelled']);
            $booking->update(['status' => 'CANCELLED', 'cancelled_by_role' => $role]);
            $enrollment->update(['status' => 'cancelled']);
            $this->audit('booking.cancelled', $booking, $userId, ['role' => $role]);

            return $session->fresh();
        });
    }

    public function audit(string $action, PaidBooking $booking, ?int $userId, array $metadata = []): void
    {
        AuditLog::create(['user_id' => $userId, 'action' => $action, 'entity_type' => PaidBooking::class,
            'entity_id' => $booking->id, 'metadata_json' => $metadata, 'created_at' => now()]);
    }
}
