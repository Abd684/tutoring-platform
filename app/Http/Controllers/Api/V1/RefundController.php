<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\Session;
use App\Services\PaidLessonBookings;
use App\Services\ShamCashPayments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RefundController extends ApiController
{
    public function store(Request $request)
    {
        $data = $request->validate(['payment_id' => ['required', 'integer'],
            'account_id' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'reason_code' => ['required', 'in:hold_expired,teacher_cancelled,service_unavailable,exceptional'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']]);
        $seed = Payment::whereKey($data['payment_id'])->where('payer_id', $request->user()->id)->firstOrFail();

        return DB::transaction(function () use ($seed, $request, $data) {
            [$payment] = app(PaidLessonBookings::class)->lockPayment($seed);
            if ($payment->provider !== 'sham_cash' || $payment->status !== 'succeeded') {
                app(ShamCashPayments::class)->fail('REFUND_NOT_ALLOWED', 'Only approved Sham Cash payments can be refunded.');
            }
            if (RefundRequest::where('active_payment_id', $payment->id)->exists()) {
                app(ShamCashPayments::class)->fail('REFUND_ALREADY_EXISTS', 'A pending, approved, or completed refund already exists.');
            }
            $refund = RefundRequest::create(['payment_id' => $payment->id, 'active_payment_id' => $payment->id,
                'user_id' => $request->user()->id, 'amount' => $payment->amount, 'currency' => $payment->currency,
                'account_id' => trim($data['account_id']), 'reason_code' => $data['reason_code'], 'reason' => trim($data['reason']), 'status' => 'PENDING_REVIEW']);
            $this->audit('refund.requested', $refund, $request);

            return $this->success($refund, 'Refund request submitted for manual review.', 201);
        });
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);
        $data = $request->validate(['status' => ['sometimes', 'in:PENDING_REVIEW,APPROVED,REJECTED,COMPLETED']]);
        $query = RefundRequest::query();
        if ($request->user()->role === 'student') {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->with('user:id,name');
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function show(RefundRequest $refund)
    {
        $this->authorize('view', $refund);

        return $this->success($refund);
    }

    public function review(Request $request, RefundRequest $refund)
    {
        $this->authorize('update', $refund);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'],
            'review_note' => ['required', 'string', 'max:2000', 'regex:/\S/u']]);

        return DB::transaction(function () use ($request, $refund, $data) {
            [$payment, , $booking] = app(PaidLessonBookings::class)->lockPayment($refund->payment);
            $refund = RefundRequest::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'PENDING_REVIEW' || $payment->status !== 'succeeded') {
                return $this->businessError('Only a pending refund for an approved payment may be reviewed.', 409);
            }
            $approve = $data['decision'] === 'approve';
            // Codes describing system events must match server state; exceptional requests require explicit admin approval.
            if ($approve && $refund->reason_code === 'hold_expired' && (! $booking || $booking->status !== 'NEEDS_RESCHEDULE' || $booking->hold_expires_at->isFuture())) {
                return $this->businessError('This booking does not require an alternative after hold expiry.', 409);
            }
            if ($approve && $refund->reason_code === 'teacher_cancelled' && (! $booking || $booking->status !== 'CANCELLED' || $booking->cancelled_by_role !== 'teacher')) {
                return $this->businessError('This lesson was not cancelled by its teacher.', 409);
            }
            $refund->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'review_note' => trim($data['review_note']),
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'active_payment_id' => $approve ? $payment->id : null]);
            if ($approve && $booking) {
                if ($booking->session_id) {
                    Session::whereKey($booking->session_id)->whereIn('status', ['scheduled', 'ongoing'])->update(['status' => 'cancelled']);
                }
                $booking->update(['status' => 'CANCELLED', 'cancelled_by_role' => 'refund']);
            }
            $this->audit('refund.'.$data['decision'], $refund, $request);

            return $this->success($refund->fresh(), $approve ? 'Refund approved. Transfer the money manually before recording completion.' : 'Refund rejected.');
        });
    }

    public function complete(Request $request, RefundRequest $refund)
    {
        $this->authorize('update', $refund);
        if (is_string($request->input('transfer_reference'))) {
            $request->merge(['transfer_reference' => trim($request->input('transfer_reference'))]);
        }
        $data = $request->validate(['transfer_reference' => ['required', 'string', 'max:255', Rule::unique('refund_requests', 'transfer_reference')]]);

        return DB::transaction(function () use ($request, $refund, $data) {
            [$payment, $enrollment, $booking] = app(PaidLessonBookings::class)->lockPayment($refund->payment);
            $refund = RefundRequest::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status !== 'APPROVED' || $payment->status !== 'succeeded') {
                return $this->businessError('Approve the refund and manually transfer the money before recording completion.', 409);
            }
            if ($data['transfer_reference'] === $payment->provider_reference) {
                return $this->businessError('The refund requires its own actual transfer reference.', 422);
            }
            // A distinct outgoing reference is claimed atomically; the original payment is untouched.
            try {
                $refund->update(['status' => 'COMPLETED', 'transfer_reference' => $data['transfer_reference'],
                    'completed_by' => $request->user()->id, 'completed_at' => now()]);
            } catch (UniqueConstraintViolationException $error) {
                app(ShamCashPayments::class)->fail('REFUND_TRANSFER_ALREADY_USED', 'This refund transfer reference was already recorded.');
            }
            $enrollment->update(['status' => 'cancelled']);
            Session::where('enrollment_id', $enrollment->id)->whereIn('status', ['scheduled', 'ongoing'])->update(['status' => 'cancelled']);
            $enrollment->groupEnrollments()->where('status', 'active')->update(['status' => 'removed', 'left_at' => now()]);
            if ($booking) {
                $booking->update(['status' => 'REFUNDED']);
            }
            $this->audit('refund.completed', $refund, $request);

            return $this->success($refund->fresh(), 'Manual refund transfer recorded.');
        });
    }

    private function audit(string $action, RefundRequest $refund, Request $request): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action,
            'entity_type' => RefundRequest::class, 'entity_id' => $refund->id,
            'metadata_json' => ['payment_id' => $refund->payment_id, 'status' => $refund->status, 'review_note' => $refund->review_note],
            'ip' => $request->ip(), 'created_at' => now()]);
    }
}
