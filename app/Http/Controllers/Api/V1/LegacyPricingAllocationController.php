<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentLessonAllocation;
use App\Models\RefundRequest;
use App\Services\OnlinePricing;
use App\Services\OnlineRefunds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyPricingAllocationController extends ApiController
{
    public function store(Request $r, Payment $payment, OnlineRefunds $refunds)
    {
        $d = $r->validate(['review_note' => ['required', 'string', 'max:2000'], 'lessons' => ['required', 'array', 'min:1'],
            'lessons.*.lesson_id' => ['required', 'distinct', 'integer', 'exists:lessons,id'], 'lessons.*.allocated_amount' => ['required', 'numeric', 'min:0'], 'lessons.*.lesson_price_snapshot' => ['required', 'numeric', 'min:0'],
            'lessons.*.content_ids_snapshot' => ['required', 'array'], 'lessons.*.content_ids_snapshot.*' => ['integer', 'exists:contents,id']]);

        return DB::transaction(function () use ($r, $payment, $refunds, $d) {
            [$p,$e] = $refunds->lock($payment);
            abort_unless($p->provider === 'sham_cash' && $p->status === 'succeeded', 422);
            abort_if(PaymentLessonAllocation::where('payment_id', $p->id)->exists(), 409, 'لا يمكن تعديل لقطة سعر مثبتة.');
            abort_if(RefundRequest::where('payment_id', $p->id)->whereIn('status', ['PENDING_REVIEW', 'APPROVED'])->exists(), 409, 'يوجد استرداد قيد المعالجة.');
            $rows = [];
            foreach ($d['lessons'] as $row) {
                $lesson = Lesson::with('unit')->findOrFail($row['lesson_id']);
                abort_unless((int) $lesson->unit->teacherSubject->teacher_id === (int) $e->teacher_id, 422);
                $matches = match ($e->enrollable_type) {
                    'teacher_subject' => (int) $lesson->unit->teacher_subject_id === (int) $e->enrollable_id,'unit' => (int) $lesson->unit_id === (int) $e->enrollable_id,'lesson' => (int) $lesson->id === (int) $e->enrollable_id,'content' => (int) Content::find($e->enrollable_id)?->lesson_id === (int) $lesson->id,default => false
                };
                abort_unless($matches, 422, 'الدرس لا يتبع الخدمة المدفوعة.');
                abort_if(Content::whereIn('id', $row['content_ids_snapshot'])->where('lesson_id', '!=', $lesson->id)->exists(), 422);
                $rows[] = $row + ['lesson_title' => $lesson->title];
            }
            app(OnlinePricing::class)->persist($p, ['lessons' => $rows]);
            AuditLog::create(['user_id' => $r->user()->id, 'action' => 'payment.legacy_allocation_recorded', 'entity_type' => Payment::class, 'entity_id' => $p->id, 'metadata_json' => ['review_note' => $d['review_note']], 'created_at' => now()]);

            return $this->success($refunds->eligibility($p));
        });
    }
}
