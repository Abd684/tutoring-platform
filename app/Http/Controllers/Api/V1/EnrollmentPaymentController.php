<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Notification;
use App\Models\Payment;
use App\Services\ShamCashPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentPaymentController extends ApiController
{
    public function store(Request $request, Enrollment $enrollment): JsonResponse
    {
        $this->authorize('pay', $enrollment);

        return response()->json([
            'status' => false,
            'code' => 'CHECKOUT_REQUIRED',
            'message' => 'Use /api/v1/payments/checkout then /api/v1/student/payments with payment proof.',
        ], 410);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('viewReceipt', $payment);

        return $this->success($payment->provider === 'sham_cash' ? app(ShamCashPayments::class)->payload($payment) : $payment);
    }

    public function review(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403);
        $this->authorize('update', $payment);
        if ($payment->provider === 'sham_cash' && ($payment->service_snapshot['type'] ?? null) === 'lesson_booking') {
            return app(PaidLessonController::class)->review($request, $payment);
        }
        $validated = $request->validate([
            'decision' => ['required', 'in:confirm,reject'],
            'rejection_reason' => [$payment->provider === 'sham_cash' ? 'required_if:decision,reject' : 'nullable', 'nullable', 'string', 'max:2000', 'regex:/\S/u'],
        ]);

        return DB::transaction(function () use ($request, $payment, $validated) {
            // Same locking order as submitting a payment: enrollment, then payment.
            abort_unless($payment->payable_type === (new Enrollment)->getMorphClass() && in_array($payment->provider, ['manual_transfer', 'sham_cash'], true), 422);
            $enrollment = Enrollment::whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->provider !== 'sham_cash' && $payment->status === 'succeeded' && $validated['decision'] === 'confirm') {
                return $this->success(['payment' => $payment, 'enrollment' => $enrollment], 'Payment already confirmed.');
            }
            if ($payment->status !== 'pending' || $enrollment->status !== 'pending') {
                return $this->businessError('Payment and enrollment must both be pending.', 409);
            }
            if ($validated['decision'] === 'reject') {
                $payment->update(['status' => 'failed']);
            } else {
                if ($payment->provider === 'sham_cash') {
                    $snapshot = $payment->service_snapshot;
                    [, $course] = app(ShamCashPayments::class)->service($snapshot['type'], $snapshot['id'], false);
                    if ($course->teacher_id !== $enrollment->teacher_id || $enrollment->student?->status !== 'active'
                        || $enrollment->student?->user?->status !== 'active') {
                        return $this->businessError('The service or student is no longer available for activation.', 409);
                    }
                }
                if ($payment->amount !== $enrollment->price || $payment->payer_id !== $enrollment->student?->user_id) {
                    return $this->businessError('Payment amount or payer does not match the enrollment.', 409);
                }
                $payment->update(['status' => 'succeeded', 'paid_at' => now()]);
                $enrollment->update(['status' => 'active', 'starts_at' => now(), 'expires_at' => null]);
            }

            if ($payment->provider === 'sham_cash') {
                $payment->update(['reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                    'rejection_reason' => $validated['decision'] === 'reject' ? trim($validated['rejection_reason']) : null]);
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'payment.'.$validated['decision'],
                    'entity_type' => Payment::class, 'entity_id' => $payment->id,
                    'metadata_json' => ['enrollment_id' => $enrollment->id, 'rejection_reason' => $payment->rejection_reason],
                    'ip' => $request->ip(), 'created_at' => now()]);
                Notification::create(['user_id' => $payment->payer_id,
                    'type' => $validated['decision'] === 'confirm' ? 'payment_approved' : 'payment_rejected',
                    'title' => $validated['decision'] === 'confirm' ? 'تم تأكيد الدفع' : 'تم رفض طلب الدفع',
                    'body' => $validated['decision'] === 'confirm' ? 'تم تأكيد عملية الدفع بنجاح، وأصبح بإمكانك الوصول إلى المحتوى الذي اشتريته.' : 'سبب الرفض: '.$payment->rejection_reason,
                    'related_payment_id' => $payment->id]);

                return $this->success(['payment' => app(ShamCashPayments::class)->payload($payment->fresh()), 'enrollment' => $enrollment->fresh()], 'Payment review completed.');
            }

            return $this->success(['payment' => $payment->fresh(), 'enrollment' => $enrollment->fresh()], 'Payment review completed.');
        });
    }
}
