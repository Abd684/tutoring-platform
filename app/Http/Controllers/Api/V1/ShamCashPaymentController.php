<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Models\PaymentSetting;
use App\Models\Student;
use App\Services\ShamCashPayments;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ShamCashPaymentController extends ApiController
{
    public function checkout(Request $request, ShamCashPayments $service)
    {
        $data = $request->validate(['service_type' => ['required', 'in:teacher_subject,unit,content,lesson_booking'], 'service_id' => ['required', 'integer', 'min:1']]);
        if ($data['service_type'] === 'lesson_booking') {
            return app(PaidLessonController::class)->checkout($request);
        }
        $settings = $this->configured($service);
        [$item, $course, $snapshot] = $service->service($data['service_type'], $data['service_id']);
        $service->ensureAvailable($request->user()->student, $item, $course);
        $checkout = PaymentCheckout::create([
            'id' => (string) Str::uuid(), 'user_id' => $request->user()->id,
            'service_type' => $data['service_type'], 'service_id' => $item->id,
            'amount' => $item->price, 'currency' => 'SYP',
            'account_id' => $settings->account_id,
            'qr_path' => $settings->qr_path,
            'expires_at' => now()->addMinutes(config('payments.checkout_minutes')),
        ]);

        return $this->success(['checkout_id' => $checkout->id, 'internal_reference' => $checkout->id,
            'amount' => $checkout->amount, 'currency' => $checkout->currency, 'service' => $snapshot,
            'account_id' => $checkout->account_id,
            'qr_url' => route('payments.checkout.qr', $checkout),
            'expires_at' => $checkout->expires_at->toIso8601String(), 'settings_url' => url('/api/v1/payment-settings')], status: 201);
    }

    public function store(Request $request, ShamCashPayments $service)
    {
        if (is_string($request->input('provider_reference'))) {
            $request->merge(['provider_reference' => trim($request->input('provider_reference'))]);
        }
        $data = $request->validate([
            'checkout_id' => ['required', 'uuid'],
            'provider_reference' => ['required', 'string', 'max:255'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.config('payments.proof_max_kb')],
        ]);
        $seed = PaymentCheckout::whereKey($data['checkout_id'])->where('user_id', $request->user()->id)->firstOrFail();
        if ($seed->service_type === 'lesson_booking') {
            return app(PaidLessonController::class)->submit($request, $seed);
        }
        $path = null;
        try {
            [$payment, $created] = DB::transaction(function () use ($request, $data, $service, &$path) {
                // Serialize submissions by student, including separate checkout sessions.
                $student = Student::where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
                $checkout = PaymentCheckout::whereKey($data['checkout_id'])->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
                if ($checkout->payment_id) {
                    $payment = Payment::findOrFail($checkout->payment_id);
                    if ($payment->provider_reference !== $data['provider_reference']) {
                        $service->fail('CHECKOUT_CONSUMED', 'This checkout has already been submitted.');
                    }

                    return [$payment, false];
                }
                if ($checkout->expires_at->isPast()) {
                    $service->fail('CHECKOUT_EXPIRED', 'Please start a new checkout.');
                }
                [$item, $course, $snapshot] = $service->service($checkout->service_type, $checkout->service_id);
                if ($item->price !== $checkout->amount) {
                    $service->fail('PRICE_CHANGED', 'The price changed. Please start a new checkout.');
                }
                $service->ensureAvailable($student, $item, $course);
                if (Payment::where('provider_reference', $data['provider_reference'])->exists()) {
                    $service->fail('TRANSFER_ALREADY_USED', 'This transfer reference has already been submitted.');
                }
                $enrollment = Enrollment::where('student_id', $student->id)->where('enrollable_type', $item->getMorphClass())
                    ->where('enrollable_id', $item->id)->where('status', 'pending')->lockForUpdate()->first();
                if ($enrollment && Payment::where('payable_type', $enrollment->getMorphClass())->where('payable_id', $enrollment->id)
                    ->whereIn('status', ['pending', 'succeeded'])->exists()) {
                    $service->fail('PAYMENT_PENDING', 'A payment already exists for this enrollment.');
                }
                if (! $enrollment) {
                    $enrollment = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $course->teacher_id,
                        'enrollable_type' => $item->getMorphClass(), 'enrollable_id' => $item->id, 'price' => $item->price, 'status' => 'pending']);
                } else {
                    $enrollment->update(['price' => $item->price, 'teacher_id' => $course->teacher_id]);
                }
                $path = $request->file('proof')->store('payment-proofs/'.$student->id, 'local');
                abort_unless($path, 500, 'Unable to store payment proof.');
                $snapshot['account_id'] = $checkout->account_id;
                $payment = Payment::create([
                    'payer_id' => $request->user()->id, 'payable_type' => $enrollment->getMorphClass(), 'payable_id' => $enrollment->id,
                    'amount' => $item->price, 'currency' => 'SYP', 'provider' => 'sham_cash',
                    'provider_reference' => $data['provider_reference'], 'sham_transfer_reference' => $data['provider_reference'],
                    'internal_reference' => $checkout->id, 'proof_path' => $path, 'proof_mime' => $request->file('proof')->getMimeType(),
                    'service_snapshot' => $snapshot, 'status' => 'pending', 'submitted_at' => now(),
                ]);
                $checkout->update(['payment_id' => $payment->id]);

                return [$payment, true];
            });
        } catch (Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                $service->fail('TRANSFER_ALREADY_USED', 'This transfer reference has already been submitted.');
            }
            throw $error;
        }

        return $this->success($service->payload($payment), $created ? 'Payment submitted for administrator review.' : 'Payment request already exists.', $created ? 201 : 200);
    }

    public function checkoutQr(Request $request, PaymentCheckout $checkout)
    {
        abort_unless((int) $checkout->user_id === (int) $request->user()->id, 404);
        abort_unless(Storage::disk('local')->exists($checkout->qr_path), 404);

        return Storage::disk('local')->response($checkout->qr_path, null,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request, ShamCashPayments $service)
    {
        $query = Payment::where('provider', 'sham_cash');
        if ($request->user()->role === 'student') {
            $query->where('payer_id', $request->user()->id);
        } else {
            abort_unless($request->user()->status === 'active', 403);
            $query->with('payer:id,name');
        }
        $filters = $request->validate(['payment_status' => ['sometimes', 'in:PENDING_REVIEW,APPROVED,REJECTED']]);
        if (isset($filters['payment_status'])) {
            $query->where('status', ['PENDING_REVIEW' => 'pending', 'APPROVED' => 'succeeded', 'REJECTED' => 'failed'][$filters['payment_status']]);
        }
        $paginator = $query->orderByDesc('id')->paginate($this->perPage($request));
        $paginator->setCollection($paginator->getCollection()->map(fn ($payment) => $service->payload($payment)));

        return $this->paginated($paginator);
    }

    public function proof(Request $request, Payment $payment)
    {
        if ($request->user()->role === 'student') {
            $this->authorize('viewReceipt', $payment);
        } else {
            $this->authorize('view', $payment);
        }
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path, 'payment-'.$payment->id.'.'.($payment->proof_mime === 'application/pdf' ? 'pdf' : ($payment->proof_mime === 'image/png' ? 'png' : 'jpg')),
            ['Content-Type' => $payment->proof_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function configured(ShamCashPayments $service): PaymentSetting
    {
        $settings = PaymentSetting::findOrFail(1);
        if (! $settings->account_id || ! $settings->qr_path) {
            $service->fail('PAYMENT_NOT_CONFIGURED', 'Sham Cash payment is not configured.', 503);
        }

        return $settings;
    }
}
