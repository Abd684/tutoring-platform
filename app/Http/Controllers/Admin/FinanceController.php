<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\EnrollmentPaymentController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\ShamCashPaymentController;
use App\Http\Controllers\Api\V1\ShamCashSettingController;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\PaidLessonBookings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceController extends AdminController
{
    public function payments(Request $request)
    {
        $data = $this->filters($request);
        $query = Payment::with('payer');
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(fn ($q) => $q->where('provider_reference', 'like', "%{$term}%")->orWhereHas('payer', fn ($q) => $q->where('name', 'like', "%{$term}%")));
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['from'])) {
            $query->whereDate('submitted_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('submitted_at', '<=', $data['to']);
        }
        if ($request->boolean('export')) {
            return $this->csv($query->orderBy('id'), ['المعرف' => 'id', 'المرجع' => 'provider_reference', 'الطالب' => 'payer.name', 'المبلغ' => 'amount', 'العملة' => 'currency', 'الحالة' => 'status'], 'payments');
        }
        $items = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $stats = ['pending' => Payment::where('status', 'pending')->count(), 'approved' => Payment::where('status', 'succeeded')->count(), 'rejected' => Payment::where('status', 'failed')->count(),
            'total' => Payment::where('status', 'succeeded')->where('currency', 'SYP')->sum('amount')];

        return view('admin.finance.payments', compact('items', 'stats'));
    }

    public function payment(Payment $payment)
    {
        $payment->load('payer');

        return view('admin.finance.payment', compact('payment'));
    }

    public function review(Request $request, Payment $payment)
    {
        if ($request->input('decision') === 'reject') {
            $request->validate(['additional_note' => ['nullable', 'string', 'max:1000']]);
            $request->merge(['rejection_reason' => trim(($request->input('rejection_reason') ?? '').' '.($request->input('additional_note') ?? ''))]);
        }

        return $this->apiResult(app(EnrollmentPaymentController::class)->review($request, $payment), 'تم تسجيل قرار مراجعة الدفعة.');
    }

    public function proof(Request $request, Payment $payment)
    {
        return app(ShamCashPaymentController::class)->proof($request, $payment);
    }

    public function refunds(Request $request)
    {
        $data = $this->filters($request);
        $request->validate(['currency' => ['nullable', 'string', 'size:3']]);
        $query = RefundRequest::with(['user', 'payment']);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(fn ($q) => $q->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$term}%"))->orWhereHas('payment', fn ($q) => $q->where('provider_reference', 'like', "%{$term}%"))->orWhere('id', ctype_digit($term) ? (int) $term : 0));
        }
        if ($request->filled('currency')) {
            $query->where('currency', strtoupper($request->input('currency')));
        }
        if (! empty($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }
        if ($request->boolean('export')) {
            return $this->csv($query->orderBy('id'), ['الطلب' => 'id', 'الطالب' => 'user.name', 'الدفع' => 'payment.provider_reference', 'المبلغ' => 'amount', 'العملة' => 'currency', 'الحالة' => 'status', 'السبب' => 'reason', 'التاريخ' => 'created_at'], 'refund-requests');
        }
        $items = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $stats = [];
        foreach (['PENDING_REVIEW', 'APPROVED', 'COMPLETED', 'REJECTED'] as $state) {
            $stats[$state] = RefundRequest::where('status', $state)->count();
        }

        return view('admin.finance.refunds', compact('items', 'stats'));
    }

    public function refund(RefundRequest $refund)
    {
        $refund->load(['user', 'payment']);

        $timeline = AuditLog::with('user')->where('entity_type', RefundRequest::class)->where('entity_id', $refund->id)->orderBy('id')->get();
        $reviewer = $refund->reviewed_by ? User::find($refund->reviewed_by) : null;
        $completer = $refund->completed_by ? User::find($refund->completed_by) : null;

        return view('admin.finance.refund', compact('refund', 'timeline', 'reviewer', 'completer'));
    }

    public function createRefund()
    {
        $payments = Payment::with('payer')->where('status', 'succeeded')->where('provider', 'sham_cash')->where('payable_type', (new Enrollment)->getMorphClass())->whereNotIn('id', RefundRequest::whereNotNull('active_payment_id')->select('active_payment_id'))->orderByDesc('id')->get();

        return view('admin.finance.refund-create', compact('payments'));
    }

    public function storeRefund(Request $request)
    {
        $data = $request->validate(['payment_id' => ['required', 'integer', 'exists:payments,id'], 'account_id' => ['required', 'string', 'max:255', 'regex:/\S/u'], 'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']]);
        $seed = Payment::findOrFail($data['payment_id']);
        if ($seed->payable_type !== (new Enrollment)->getMorphClass()) {
            throw ValidationException::withMessages(['payment_id' => 'يجب أن ترتبط الدفعة بتسجيل طالب.']);
        }
        try {
            $refund = DB::transaction(function () use ($seed, $data) {
                [$payment] = app(PaidLessonBookings::class)->lockPayment($seed);
                if ($payment->status !== 'succeeded' || $payment->provider !== 'sham_cash' || $payment->payer?->role !== 'student') {
                    throw ValidationException::withMessages(['payment_id' => 'اختر دفعة طالب معتمدة عبر شام كاش.']);
                }
                if (RefundRequest::where('active_payment_id', $payment->id)->exists()) {
                    throw ValidationException::withMessages(['payment_id' => 'يوجد طلب استرداد قائم لهذه الدفعة.']);
                }
                $refund = RefundRequest::create(['payment_id' => $payment->id, 'active_payment_id' => $payment->id, 'user_id' => $payment->payer_id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'account_id' => trim($data['account_id']), 'reason_code' => 'exceptional', 'reason' => trim($data['reason']), 'status' => 'PENDING_REVIEW']);
                $this->audit('refund.requested', $refund, ['source' => 'administrative_exceptional']);

                return $refund;
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['payment_id' => 'يوجد طلب استرداد قائم لهذه الدفعة.']);
        }

        return redirect()->route('admin.refunds.show', $refund)->with('success', 'تم إنشاء طلب استثنائي بانتظار المراجعة، دون تنفيذ أي تحويل.');
    }

    public function reviewRefund(Request $request, RefundRequest $refund)
    {
        return $this->apiResult(app(RefundController::class)->review($request, $refund), 'تم تسجيل قرار الاسترداد.');
    }

    public function completeRefund(Request $request, RefundRequest $refund)
    {
        return $this->apiResult(app(RefundController::class)->complete($request, $refund), 'تم توثيق إتمام التحويل اليدوي.');
    }

    public function paymentSettings()
    {
        $settings = PaymentSetting::findOrFail(1);

        return view('admin.finance.settings', compact('settings'));
    }

    public function savePaymentSettings(Request $request)
    {
        return $this->apiResult(app(ShamCashSettingController::class)->update($request), 'تم حفظ حساب الدفع وصورة QR.');
    }

    public function qr(Request $request)
    {
        return app(ShamCashSettingController::class)->qr($request);
    }
}
