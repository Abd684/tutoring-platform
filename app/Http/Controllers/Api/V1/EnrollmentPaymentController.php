<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentPaymentController extends ApiController
{
    public function store(Request $request, Enrollment $enrollment): JsonResponse
    {
        $this->authorize('accessStudent', Payment::class);
        $this->authorize('pay', $enrollment);
        $validated = $request->validate([
            'provider_reference' => ['required', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($request, $enrollment, $validated) {
            $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            if ($enrollment->status !== 'pending' || (float) $enrollment->price <= 0) {
                return $this->businessError('Only a pending enrollment with a positive price can be paid.', 409);
            }
            $existing = Payment::where('payable_type', $enrollment->getMorphClass())->where('payable_id', $enrollment->id)
                ->whereIn('status', ['pending', 'succeeded'])->first();
            if ($existing) {
                return $this->success($existing, 'Payment request already exists.');
            }
            $payment = Payment::create([
                'payer_id' => $request->user()->id, 'payable_type' => $enrollment->getMorphClass(),
                'payable_id' => $enrollment->id, 'amount' => $enrollment->price,
                'currency' => config('payments.currency'), 'provider' => 'manual_transfer',
                'provider_reference' => $validated['provider_reference'], 'status' => 'pending',
            ]);

            return $this->success($payment, 'Transfer submitted for administrator verification.', 201);
        });
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('accessStudent', Payment::class);
        $this->authorize('view', $payment);

        return $this->success($payment);
    }

    public function review(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403);
        $this->authorize('update', $payment);
        $validated = $request->validate(['decision' => ['required', 'in:confirm,reject']]);

        return DB::transaction(function () use ($payment, $validated) {
            // Same locking order as submitting a payment: enrollment, then payment.
            abort_unless($payment->payable_type === (new Enrollment)->getMorphClass() && $payment->provider === 'manual_transfer', 422);
            $enrollment = Enrollment::whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'succeeded' && $validated['decision'] === 'confirm') {
                return $this->success(['payment' => $payment, 'enrollment' => $enrollment], 'Payment already confirmed.');
            }
            if ($payment->status !== 'pending' || $enrollment->status !== 'pending') {
                return $this->businessError('Payment and enrollment must both be pending.', 409);
            }
            if ($validated['decision'] === 'reject') {
                $payment->update(['status' => 'failed']);
            } else {
                if ($payment->amount !== $enrollment->price || $payment->payer_id !== $enrollment->student?->user_id) {
                    return $this->businessError('Payment amount or payer does not match the enrollment.', 409);
                }
                $payment->update(['status' => 'succeeded', 'paid_at' => now()]);
                $enrollment->update(['status' => 'active', 'starts_at' => now(), 'expires_at' => null]);
            }

            return $this->success(['payment' => $payment->fresh(), 'enrollment' => $enrollment->fresh()], 'Payment review completed.');
        });
    }
}
