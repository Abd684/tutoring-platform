<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\EnrollmentPaymentController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\ShamCashPaymentController;
use App\Http\Controllers\Api\V1\ShamCashSettingController;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\RefundRequest;
use Illuminate\Http\Request;

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
        $query = RefundRequest::with(['user', 'payment']);
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%'));
        }
        $items = $query->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.finance.refunds', compact('items'));
    }

    public function refund(RefundRequest $refund)
    {
        $refund->load(['user', 'payment']);

        return view('admin.finance.refund', compact('refund'));
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
