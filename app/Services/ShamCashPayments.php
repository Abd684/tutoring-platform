<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\PaidBooking;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\Student;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;

class ShamCashPayments
{
    public function fail(string $code, string $message, int $status = 409): never
    {
        throw new HttpResponseException(response()->json(['status' => false, 'code' => $code, 'message' => $message], $status));
    }

    /** Resolve current server data; never trust a client amount, teacher, or title. */
    public function service(string $type, int $id, bool $requirePrice = true): array
    {
        if (! in_array($type, ['teacher_subject', 'unit', 'lesson', 'content'], true)) {
            $this->fail('ONLINE_SERVICE_REQUIRED', 'دفع المنصة للمحتوى الإلكتروني فقط. الجلسات الفيزيائية تُدفع لدى الأستاذ.', 422);
        }
        $item = match ($type) {
            'teacher_subject' => TeacherSubject::with('subject', 'teacher.user')->findOrFail($id),
            'unit' => Unit::with('teacherSubject.subject', 'teacherSubject.teacher.user')->findOrFail($id),
            'lesson' => Lesson::with('unit.teacherSubject.subject', 'unit.teacherSubject.teacher.user')->findOrFail($id),
            'content' => Content::with('lesson.unit.teacherSubject.subject', 'lesson.unit.teacherSubject.teacher.user')->findOrFail($id),
        };
        $course = match ($type) {
            'teacher_subject' => $item,
            'unit' => $item->teacherSubject,
            'lesson' => $item->unit?->teacherSubject,
            'content' => $item->lesson?->unit?->teacherSubject,
        };
        $published = match ($type) {
            'teacher_subject' => true,
            'unit' => $item->status === 'published',
            'lesson' => $item->status === 'published' && $item->unit?->status === 'published',
            'content' => $item->status === 'published' && $item->lesson?->status === 'published' && $item->lesson?->unit?->status === 'published',
        };
        if (! $published || $course?->status !== 'active' || $course->subject?->status !== 'active'
            || $course->teacher?->status !== 'active' || $course->teacher?->user?->status !== 'active') {
            $this->fail('PURCHASE_UNAVAILABLE', 'This service is not available for purchase.');
        }
        if ($item instanceof Content && $item->type === 'quiz' && $item->quizzes()->where('quiz_type', 'simple')->exists()) {
            $this->fail('SIMPLE_QUIZ_FREE', 'الاختبار البسيط مجاني ولا يحتاج إلى شراء.', 422);
        }
        if ($requirePrice && ($item->price === null || (float) $item->price <= 0)) {
            $this->fail('PRICE_NOT_SET', 'A positive service price is required.');
        }

        return [$item, $course, [
            'type' => $type, 'id' => $item->id,
            'title' => $type === 'teacher_subject' ? $course->subject->name : $item->title,
            'subject_name' => $course->subject->name,
            'teacher' => ['id' => $course->teacher_id, 'name' => $course->teacher->user->name],
        ]];
    }

    public function ensureAvailable(Student $student, Model $item, TeacherSubject $course): void
    {
        $coverage = app(ContentAccess::class)->enrollments($student)
            ->where(function ($q) use ($item, $course) {
                $q->where(fn ($q) => $q->where('enrollable_type', $item->getMorphClass())->where('enrollable_id', $item->id))
                    ->orWhere(fn ($q) => $q->where('enrollable_type', 'teacher_subject')->where('enrollable_id', $course->id));
                if ($item instanceof Lesson) {
                    $q->orWhere(fn ($q) => $q->where('enrollable_type', 'unit')->where('enrollable_id', $item->unit_id));
                }
                if ($item instanceof Content) {
                    $q->orWhere(fn ($q) => $q->where('enrollable_type', 'lesson')->where('enrollable_id', $item->lesson_id));
                    $q->orWhere(fn ($q) => $q->where('enrollable_type', 'unit')->where('enrollable_id', $item->lesson->unit_id));
                }
            })->exists();
        if ($coverage) {
            $this->fail('ALREADY_OWNED', 'The student already owns this service.');
        }
        $enrollments = Enrollment::where('student_id', $student->id)
            ->where('enrollable_type', $item->getMorphClass())->where('enrollable_id', $item->id)->select('id');
        if (Payment::where('payable_type', (new Enrollment)->getMorphClass())
            ->whereIn('payable_id', $enrollments)->where('status', 'pending')->exists()) {
            $this->fail('PAYMENT_PENDING', 'A payment for this service is already under review.');
        }
    }

    public function payload(Payment $payment): array
    {
        $booking = PaidBooking::where('payment_id', $payment->id)->first();
        $refund = RefundRequest::where('payment_id', $payment->id)->latest('id')->first();
        $bookingStatus = $booking?->status;
        if ($bookingStatus === 'HELD' && $booking->hold_expires_at->lte(now())) {
            $bookingStatus = 'EXPIRED';
        }

        $eligibility = app(OnlineRefunds::class)->eligibility($payment);
        $returned = OnlinePricing::cents($eligibility['refunded_amount']);
        $financialStatus = $payment->status === 'pending' ? 'pending' : ($payment->status === 'failed' ? 'rejected' : ($returned >= OnlinePricing::cents($payment->amount) && $returned > 0 ? 'refunded' : ($returned > 0 ? 'partially_refunded' : 'confirmed')));

        return array_merge($payment->toArray(), [
            'financial_status' => $financialStatus, 'refundable_amount' => $eligibility['refundable_amount'], 'refunded_amount' => $eligibility['refunded_amount'],
            'payment_status' => match ($payment->status) {
                'pending' => 'PENDING_REVIEW', 'succeeded' => 'APPROVED', 'failed' => 'REJECTED', default => $payment->status,
            },
            'proof_url' => $payment->proof_path ? route('payments.proof', $payment) : null,
            'booking' => $booking ? array_merge($booking->toArray(), ['status' => $bookingStatus]) : null,
            'refund' => $refund?->only(['id', 'status', 'amount', 'currency', 'completed_at']),
            'can_request_refund' => $eligibility['eligible'],
            'can_reschedule' => $payment->status === 'succeeded' && (! $refund || $refund->status === 'REJECTED')
                && ($bookingStatus === 'NEEDS_RESCHEDULE' || ($bookingStatus === 'CANCELLED' && $booking->cancelled_by_role === 'teacher')),
        ]);
    }
}
