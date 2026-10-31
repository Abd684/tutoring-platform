<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ContentCompletion;
use App\Models\Enrollment;
use App\Models\LessonCompletion;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentLessonAllocation;
use App\Models\RefundLessonItem;
use App\Models\RefundRequest;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;

class OnlineRefunds
{
    public function lock(Payment $seed): array
    {
        abort_unless($seed->payable_type === (new Enrollment)->getMorphClass(), 422, 'دفعة غير مرتبطة باشتراك.');
        $e = Enrollment::findOrFail($seed->payable_id);
        Teacher::whereKey($e->teacher_id)->lockForUpdate()->firstOrFail();
        Student::whereKey($e->student_id)->lockForUpdate()->firstOrFail();

        return [Payment::whereKey($seed->id)->lockForUpdate()->firstOrFail(), Enrollment::whereKey($e->id)->lockForUpdate()->firstOrFail()];
    }

    public function eligibility(Payment $p, ?int $ignoreRefundId = null): array
    {
        $base = ['payment_id' => $p->id, 'currency' => $p->currency, 'eligible' => false, 'refundable_amount' => '0.00', 'consumed_amount' => '0.00', 'refunded_amount' => '0.00', 'reserved_amount' => '0.00', 'lesson_allocations' => []];
        if ($p->provider !== 'sham_cash' || $p->status !== 'succeeded') {
            return $base + ['code' => 'PAYMENT_NOT_CONFIRMED', 'message' => 'لا توجد دفعة مؤكدة قابلة للاسترداد.'];
        }
        $e = Enrollment::find($p->payable_id);
        if (! $e) {
            return $base + ['code' => 'ENROLLMENT_MISSING'];
        }
        $rows = PaymentLessonAllocation::where('payment_id', $p->id)->orderBy('id')->get();
        $refunds = RefundRequest::where('payment_id', $p->id)->when($ignoreRefundId, fn ($q) => $q->whereKeyNot($ignoreRefundId))->whereIn('status', ['PENDING_REVIEW', 'APPROVED', 'COMPLETED'])->get();
        $done = $refunds->where('status', 'COMPLETED')->sum(fn ($r) => OnlinePricing::cents($r->amount));
        $reserved = $refunds->whereIn('status', ['PENDING_REVIEW', 'APPROVED'])->sum(fn ($r) => OnlinePricing::cents($r->amount));
        $base['refunded_amount'] = OnlinePricing::decimal($done);
        $base['reserved_amount'] = OnlinePricing::decimal($reserved);
        if ($rows->isEmpty()) {
            return $base + ['code' => 'PRICING_SNAPSHOT_REQUIRED', 'message' => 'تحتاج هذه الدفعة القديمة مراجعة توزيع أسعارها قبل حساب الاسترداد.'];
        }
        $items = RefundLessonItem::whereIn('refund_request_id', $refunds->pluck('id'))->get()->groupBy('payment_lesson_allocation_id');
        $consumed = 0;
        $available = 0;
        $details = [];
        $completed = ContentCompletion::where('student_id', $e->student_id)->pluck('content_id')->flip();
        $completedLessons = LessonCompletion::where('student_id', $e->student_id)->pluck('lesson_id')->flip();
        foreach ($rows as $a) {
            $ids = $a->content_ids_snapshot ?? [];
            $used = $completedLessons->has($a->lesson_id) || ($ids !== [] && collect($ids)->every(fn ($id) => $completed->has($id)));
            $amount = OnlinePricing::cents($a->allocated_amount);
            $claimed = ($items->get($a->id) ?? collect())->sum(fn ($i) => OnlinePricing::cents($i->amount));
            if ($used) {
                $consumed += max(0, $amount - $claimed);
            }
            $left = $used ? 0 : max(0, $amount - $claimed);
            $available += $left;
            $details[] = ['allocation_id' => $a->id, 'lesson_id' => $a->lesson_id, 'lesson_title' => $a->lesson_title, 'allocated_amount' => $a->allocated_amount, 'consumed' => $used, 'refundable_amount' => OnlinePricing::decimal($left)];
        }
        // Older refunds without item rows still reduce the total; never repay them.
        $available = min($available, max(0, OnlinePricing::cents($p->amount) - $done - $reserved - $consumed));
        $base['consumed_amount'] = OnlinePricing::decimal($consumed);
        $base['refundable_amount'] = OnlinePricing::decimal($available);
        $base['eligible'] = $available > 0 && $reserved === 0;
        $base['lesson_allocations'] = $details;

        return $base + ['code' => $base['eligible'] ? 'REFUND_AVAILABLE' : ($reserved > 0 ? 'REFUND_UNDER_REVIEW' : 'NO_REFUNDABLE_BALANCE'), 'message' => $base['eligible'] ? 'يوجد مبلغ قابل للاسترداد.' : ($reserved > 0 ? 'لديك طلب استرداد قيد المعالجة.' : 'لا يوجد مبلغ قابل للاسترداد حالياً.')];
    }

    public function request(Payment $p, Enrollment $e, array $data, ?int $actor = null): RefundRequest
    {
        $info = $this->eligibility($p);
        if (! $info['eligible']) {
            app(ShamCashPayments::class)->fail($info['code'], $info['message']);
        }
        $refund = RefundRequest::create(['payment_id' => $p->id, 'active_payment_id' => $p->id, 'user_id' => $p->payer_id, 'amount' => $info['refundable_amount'], 'currency' => $p->currency,
            'account_id' => $data['account_id'] ?? '', 'reason_code' => $data['reason_code'] ?? 'exceptional', 'reason' => $data['reason'] ?? 'طلب استرداد المحتوى غير المستهلك.',
            'status' => 'PENDING_REVIEW', 'payout_details_required' => empty($data['account_id']), 'original_transfer_date' => $data['original_transfer_date'] ?? null, 'original_transfer_number' => $data['original_transfer_number'] ?? null,
            'original_proof_path' => $data['original_proof_path'] ?? null, 'original_proof_mime' => $data['original_proof_mime'] ?? null]);
        $remaining = OnlinePricing::cents($info['refundable_amount']);
        foreach ($info['lesson_allocations'] as $row) {
            $n = min($remaining, OnlinePricing::cents($row['refundable_amount']));
            if ($n <= 0) {
                continue;
            }RefundLessonItem::create(['refund_request_id' => $refund->id, 'payment_lesson_allocation_id' => $row['allocation_id'], 'amount' => OnlinePricing::decimal($n)]);
            $remaining -= $n;
        }
        $e->update(['access_frozen_at' => now()]);
        $this->audit('refund.requested', $refund, $actor);
        $this->notify($p->payer_id, 'refund_pending_review', 'طلب الاسترداد قيد المراجعة', 'تم تسجيل طلب استرداد المحتوى غير المستهلك.', $refund);
        $teacher = $e->teacher;
        $this->notify($teacher->user_id, 'student_refund_requested', 'طلب استرداد لطالب', 'يوجد طلب استرداد للمحتوى غير المستهلك من أحد الطلاب.', $refund);

        return $refund;
    }

    public function notify(int $userId, string $type, string $title, string $body, RefundRequest $r): void
    {
        Notification::create(['user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'related_payment_id' => $r->payment_id, 'data' => ['refund_request_id' => $r->id, 'payment_id' => $r->payment_id]]);
    }

    public function audit(string $action, RefundRequest $r, ?int $actor): void
    {
        AuditLog::create(['user_id' => $actor, 'action' => $action, 'entity_type' => RefundRequest::class, 'entity_id' => $r->id, 'metadata_json' => ['payment_id' => $r->payment_id, 'amount' => $r->amount, 'status' => $r->status], 'created_at' => now()]);
    }

    public function teacherSuspended(Teacher $teacher, int $actor): void
    {
        $payments = Payment::where('payable_type', (new Enrollment)->getMorphClass())->where('status', 'succeeded')->where('provider', 'sham_cash')->whereIn('payable_id', Enrollment::where('teacher_id', $teacher->id)->select('id'))->orderBy('id')->get();
        foreach ($payments as $seed) {
            DB::transaction(function () use ($seed, $actor) {
                [$p,$e] = $this->lock($seed);
                $info = $this->eligibility($p);
                if ($info['eligible']) {
                    $this->request($p, $e, ['reason_code' => 'teacher_suspended', 'reason' => 'تجميد حساب الأستاذ؛ استرداد المحتوى غير المستهلك.'], $actor);
                } elseif (($info['code'] ?? null) === 'PRICING_SNAPSHOT_REQUIRED') {
                    Notification::create(['user_id' => $actor, 'type' => 'legacy_refund_review_required', 'title' => 'دفعة قديمة تحتاج مراجعة', 'body' => 'تحتاج هذه الدفعة توزيع أسعار موثقاً لحساب رصيد الطالب بعد تجميد الأستاذ.', 'related_payment_id' => $p->id, 'data' => ['payment_id' => $p->id]]);
                }
            });
        }
    }
}
