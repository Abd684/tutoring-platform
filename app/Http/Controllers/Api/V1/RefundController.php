<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\RefundRequest;
use App\Services\OnlinePricing;
use App\Services\OnlineRefunds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class RefundController extends ApiController
{
    private function payload(RefundRequest $r): array
    {
        return $r->toArray() + ['refund_status' => match ($r->status) {
            'PENDING_REVIEW' => 'pending_review','APPROVED' => 'approved','COMPLETED' => 'refund_sent','REJECTED' => $r->rejection_code ?: 'rejected',default => strtolower($r->status)
        }, 'refund_proof_url' => $r->refund_proof_path ? route('refunds.proof', ['refund' => $r->id, 'kind' => 'refund']) : null, 'original_proof_url' => $r->original_proof_path ? route('refunds.proof', ['refund' => $r->id, 'kind' => 'original']) : null, 'refunded_amount' => $r->status === 'COMPLETED' ? $r->amount : '0.00'];
    }

    public function eligibility(Request $r, OnlineRefunds $service)
    {
        $d = $r->validate(['payment_id' => ['sometimes', 'integer', 'min:1']]);
        $q = Payment::where('payer_id', $r->user()->id)->where('provider', 'sham_cash')->where('status', 'succeeded');
        if (isset($d['payment_id'])) {
            return $this->success($service->eligibility($q->findOrFail($d['payment_id'])));
        }
        $p = $q->orderByDesc('id')->paginate($this->perPage($r));
        $p->setCollection($p->getCollection()->map(fn ($x) => $service->eligibility($x)));

        return $this->paginated($p);
    }

    public function store(Request $r, OnlineRefunds $service)
    {
        $d = $r->validate(['payment_id' => ['required', 'integer'], 'account_id' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'reason_code' => ['sometimes', 'in:exceptional,service_unavailable,teacher_suspended'], 'reason' => ['sometimes', 'string', 'max:2000'],
            'original_transfer_date' => ['nullable', 'date', 'before_or_equal:now'], 'original_transfer_number' => ['nullable', 'string', 'max:255'],
            'original_proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]);
        $seed = Payment::where('payer_id', $r->user()->id)->findOrFail($d['payment_id']);
        $path = null;
        try {
            $refund = DB::transaction(function () use ($r, $service, $seed, $d, &$path) {
                [$p,$e] = $service->lock($seed);
                $data = $d;
                if ($r->hasFile('original_proof')) {
                    $path = $r->file('original_proof')->store('refund-original-proofs/'.$r->user()->id, 'local');
                    $data['original_proof_path'] = $path;
                    $data['original_proof_mime'] = $r->file('original_proof')->getMimeType();
                }

                return $service->request($p, $e, $data, $r->user()->id);
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $error;
        }

        return $this->success($this->payload($refund), 'تم تقديم طلب الاسترداد للمراجعة.', 201);
    }

    public function index(Request $r)
    {
        abort_unless($r->user()->status === 'active', 403);
        $d = $r->validate(['status' => ['sometimes', 'in:PENDING_REVIEW,APPROVED,REJECTED,COMPLETED']]);
        $q = RefundRequest::query()->when($r->user()->role === 'student', fn ($q) => $q->where('user_id', $r->user()->id))->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']));
        $p = $q->orderByDesc('id')->paginate($this->perPage($r));
        $p->setCollection($p->getCollection()->map(fn ($x) => $this->payload($x)));

        return $this->paginated($p);
    }

    public function show(RefundRequest $refund)
    {
        $this->authorize('view', $refund);

        return $this->success($this->payload($refund));
    }

    public function payout(Request $r, RefundRequest $refund)
    {
        abort_unless($refund->user_id === $r->user()->id, 404);
        $d = $r->validate(['account_id' => ['required', 'string', 'max:255', 'regex:/\S/u']]);

        return DB::transaction(function () use ($refund, $d) {
            $x = RefundRequest::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($x->status, ['PENDING_REVIEW', 'APPROVED'], true), 409);
            $x->update(['account_id' => trim($d['account_id']), 'payout_details_required' => false]);

            return $this->success($this->payload($x));
        });
    }

    public function review(Request $r, RefundRequest $refund, ?OnlineRefunds $service = null)
    {
        $service ??= app(OnlineRefunds::class);
        $this->authorize('update', $refund);
        $d = $r->validate(['decision' => ['required', 'in:approve,reject'], 'review_note' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'rejection_code' => ['required_if:decision,reject', 'nullable', 'in:rejected_no_balance,rejected_invalid_data,rejected']]);

        return DB::transaction(function () use ($r, $refund, $service, $d) {
            [$p,$e] = $service->lock($refund->payment);
            $x = RefundRequest::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            abort_unless($x->status === 'PENDING_REVIEW' && $p->status === 'succeeded', 409, 'تمت معالجة هذا الطلب بالفعل.');
            $approve = $d['decision'] === 'approve';
            if ($approve) {
                $fresh = $service->eligibility($p, $x->id);
                abort_if(($fresh['code'] ?? null) === 'PRICING_SNAPSHOT_REQUIRED' || OnlinePricing::cents($x->amount) > OnlinePricing::cents($fresh['refundable_amount']), 409, 'تغير الرصيد القابل للاسترداد أو تحتاج الدفعة مراجعة أسعار. أعد مراجعة الطلب.');
            }
            $x->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'review_note' => trim($d['review_note']), 'rejection_code' => $approve ? null : $d['rejection_code'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'active_payment_id' => $approve ? $p->id : null]);
            if (! $approve) {
                $e->update(['access_frozen_at' => null]);
            }$service->audit('refund.'.$d['decision'], $x, $r->user()->id);
            $service->notify($x->user_id, $approve ? 'refund_approved' : $x->rejection_code, $approve ? 'تمت الموافقة على الاسترداد' : 'نتيجة مراجعة الاسترداد', $approve ? 'تمت الموافقة على الطلب، وسيظهر إثبات التحويل بعد إعادة المبلغ.' : ($x->rejection_code === 'rejected_no_balance' ? 'تمت مراجعة حسابك، ولم نجد مبلغاً مستحقاً للاسترداد حالياً.' : $x->review_note), $x);

            return $this->success($this->payload($x));
        });
    }

    public function complete(Request $r, RefundRequest $refund, ?OnlineRefunds $service = null)
    {
        $service ??= app(OnlineRefunds::class);
        $this->authorize('update', $refund);
        $d = $r->validate(['transfer_reference' => ['nullable', 'string', 'max:255', Rule::unique('refund_requests', 'transfer_reference')],
            'refund_transferred_at' => ['required', 'date', 'before_or_equal:now'], 'refund_proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120']]);
        $path = null;
        try {
            $x = DB::transaction(function () use ($r, $refund, $service, $d, &$path) {
                [$p,$e] = $service->lock($refund->payment);
                $x = RefundRequest::whereKey($refund->id)->lockForUpdate()->firstOrFail();
                abort_unless($x->status === 'APPROVED' && $p->status === 'succeeded', 409, 'يجب اعتماد الاسترداد وتحويل المبلغ أولاً.');
                abort_if($x->payout_details_required || trim($x->account_id) === '', 422, 'أكمل رقم حساب الطالب أولاً.');
                if (! empty($d['transfer_reference'])) {
                    abort_if($d['transfer_reference'] === $p->provider_reference, 422, 'رقم التحويل الأصلي ليس رقم تحويل الاسترداد.');
                }
                $fresh = $service->eligibility($p, $x->id);
                abort_if(($fresh['code'] ?? null) === 'PRICING_SNAPSHOT_REQUIRED' || OnlinePricing::cents($x->amount) > OnlinePricing::cents($fresh['refundable_amount']), 409, 'تغير الرصيد القابل للاسترداد؛ يجب إعادة مراجعة الطلب قبل التحويل.');
                $already = RefundRequest::where('payment_id', $p->id)->where('status', 'COMPLETED')->get()->sum(fn ($z) => OnlinePricing::cents($z->amount));
                abort_if($already + OnlinePricing::cents($x->amount) > OnlinePricing::cents($p->amount), 409, 'المبلغ يتجاوز المتبقي من الدفعة.');
                $path = $r->file('refund_proof')->store('refund-proofs/'.$x->user_id, 'local');
                abort_unless($path, 500, 'تعذر حفظ مستند التحويل.');
                $x->update(['status' => 'COMPLETED', 'active_payment_id' => null, 'transfer_reference' => $d['transfer_reference'] ?? null, 'refund_proof_path' => $path, 'refund_proof_mime' => $r->file('refund_proof')->getMimeType(), 'refund_transferred_at' => $d['refund_transferred_at'], 'completed_by' => $r->user()->id, 'completed_at' => now()]);
                $allReturned = $already + OnlinePricing::cents($x->amount) >= OnlinePricing::cents($p->amount);
                $e->update(['access_frozen_at' => null, 'status' => $allReturned ? 'cancelled' : $e->status]);
                $service->audit('refund.completed', $x, $r->user()->id);
                $service->notify($x->user_id, 'refund_sent', 'تم الاسترداد', 'تمت إعادة المبلغ إلى حساب شام كاش الخاص بك. يمكنك عرض إثبات التحويل.', $x);
                $service->notify($e->teacher->user_id, 'student_refund_completed', 'اكتمال استرداد لطالب', 'اكتملت إعادة قيمة المحتوى غير المستهلك للطالب.', $x);

                return $x;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $error;
        }

        return $this->success($this->payload($x), 'تم تسجيل تحويل مبلغ الاسترداد وإثباته.');
    }

    public function proof(Request $r, RefundRequest $refund, string $kind)
    {
        $this->authorize('view', $refund);
        abort_unless(in_array($kind, ['refund', 'original'], true), 404);
        $path = $kind === 'refund' ? $refund->refund_proof_path : $refund->original_proof_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
