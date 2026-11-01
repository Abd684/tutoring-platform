<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\IndividualOffer;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentLessonAllocation;
use App\Models\PaymentSetting;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\RefundRequest;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaidBookingRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $studentUser;

    private Student $student;

    private User $teacherUser;

    private Teacher $teacher;

    private User $admin;

    private TeacherSubject $course;

    private Unit $unit;

    private Lesson $first;

    private Lesson $second;

    private Content $video;

    private Content $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('qr.png', 'demo');
        PaymentSetting::findOrFail(1)->update(['account_id' => 'DEMO', 'qr_path' => 'qr.png']);
        $this->studentUser = User::factory()->create();
        $this->student = Student::create(['user_id' => $this->studentUser->id, 'status' => 'active']);
        $this->teacherUser = User::factory()->create(['role' => 'teacher']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'status' => 'active']);
        $this->admin = User::factory()->create(['role' => 'company_admin']);
        $this->course = TeacherSubject::create(['teacher_id' => $this->teacher->id, 'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id, 'price' => 100, 'status' => 'active']);
        $this->unit = Unit::create(['teacher_subject_id' => $this->course->id, 'title' => 'Unit', 'price' => 100, 'status' => 'published']);
        $this->first = Lesson::create(['unit_id' => $this->unit->id, 'title' => 'First', 'price' => 60, 'status' => 'published']);
        $this->second = Lesson::create(['unit_id' => $this->unit->id, 'title' => 'Second', 'price' => 140, 'status' => 'published']);
        $this->video = Content::create(['lesson_id' => $this->first->id, 'type' => 'video', 'title' => 'Video', 'status' => 'published', 'price' => 60]);
        $this->other = Content::create(['lesson_id' => $this->second->id, 'type' => 'pdf', 'title' => 'PDF', 'status' => 'published', 'price' => 140]);
        Sanctum::actingAs($this->studentUser);
    }

    private function submit(string $type = 'teacher_subject', ?int $id = null): Payment
    {
        $c = $this->postJson('/api/v1/payments/checkout', ['service_type' => $type, 'service_id' => $id ?? $this->course->id])->assertCreated()->json('data.checkout_id');
        $id = $this->postJson('/api/v1/student/payments', ['checkout_id' => $c, 'provider_reference' => 'IN-'.uniqid(), 'proof' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'), 'amount' => 1])->assertCreated()->assertJsonPath('data.amount', $type === 'lesson' ? '60.00' : '100.00')->json('data.id');

        return Payment::findOrFail($id);
    }

    private function approve(Payment $p): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/review', ['decision' => 'confirm'])->assertOk();
        Sanctum::actingAs($this->studentUser);
    }

    private function refund(Payment $p): int
    {
        return $this->postJson('/api/v1/student/refunds', ['payment_id' => $p->id, 'account_id' => 'STUDENT-ACCOUNT', 'reason' => 'Unused lessons'])->assertCreated()->json('data.id');
    }

    private function approveRefund(int $id): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/refunds/'.$id.'/review', ['decision' => 'approve', 'review_note' => 'Verified'])->assertOk();
    }

    private function finish(int $id, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/refunds/'.$id.'/complete', $extra + ['refund_transferred_at' => now()->toIso8601String(), 'refund_proof' => UploadedFile::fake()->create('refund.pdf', 10, 'application/pdf')]);
    }

    public function test_purchase_snapshot_allocates_discount_proportionally_and_does_not_change_with_prices(): void
    {
        $p = $this->submit();
        $rows = PaymentLessonAllocation::where('payment_id', $p->id)->orderBy('lesson_id')->get();
        $this->assertSame(['30.00', '70.00'], $rows->pluck('allocated_amount')->all());
        $this->first->update(['price' => 999]);
        $this->second->update(['price' => 1]);
        $this->approve($p);
        $this->getJson('/api/v1/student/refunds/eligibility?payment_id='.$p->id)->assertOk()->assertJsonPath('data.refundable_amount', '100.00');
        $this->getJson('/api/v1/contents/'.$this->video->id.'/assets')->assertOk();
        $this->assertSame('active', Enrollment::find($p->payable_id)->status);
    }

    public function test_opening_lesson_does_not_consume_value_but_completion_does(): void
    {
        $p = $this->submit();
        $this->approve($p);
        $this->getJson('/api/v1/student/lessons/'.$this->first->id.'/content')->assertOk();
        $this->getJson('/api/v1/student/refunds/eligibility?payment_id='.$p->id)->assertJsonPath('data.refundable_amount', '100.00');
        $this->postJson('/api/v1/student/contents/'.$this->video->id.'/complete')->assertOk();
        $this->assertDatabaseHas('lesson_completions', ['student_id' => $this->student->id, 'lesson_id' => $this->first->id]);
        $this->getJson('/api/v1/student/refunds/eligibility?payment_id='.$p->id)->assertJsonPath('data.refundable_amount', '70.00')->assertJsonPath('data.consumed_amount', '30.00');
    }

    public function test_refund_reserves_balance_freezes_access_and_rejects_duplicate_requests(): void
    {
        $p = $this->submit();
        $this->approve($p);
        $this->refund($p);
        $this->postJson('/api/v1/student/refunds', ['payment_id' => $p->id, 'account_id' => 'A'])->assertConflict();
        $this->getJson('/api/v1/contents/'.$this->video->id.'/assets')->assertNotFound();
        $this->getJson('/api/v1/student/refunds/eligibility?payment_id='.$p->id)->assertJsonPath('data.reserved_amount', '100.00')->assertJsonPath('data.eligible', false);
    }

    public function test_partial_refund_needs_proof_but_transfer_reference_is_optional_and_returned_lessons_are_locked(): void
    {
        $p = $this->submit();
        $this->approve($p);
        $this->postJson('/api/v1/student/contents/'.$this->video->id.'/complete')->assertOk();
        $id = $this->refund($p);
        $this->approveRefund($id);
        $this->postJson('/api/v1/admin/refunds/'.$id.'/complete', ['refund_transferred_at' => now()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('refund_proof');
        $res = $this->finish($id)->assertOk()->assertJsonPath('data.amount', '70.00')->assertJsonPath('data.refund_status', 'refund_sent')->assertJsonMissingPath('data.refund_proof_path');
        $url = $res->json('data.refund_proof_url');
        Sanctum::actingAs($this->studentUser);
        $this->get($url)->assertOk();
        $this->getJson('/api/v1/contents/'.$this->video->id.'/assets')->assertOk();
        $this->getJson('/api/v1/contents/'.$this->other->id.'/assets')->assertNotFound();
        $this->getJson('/api/v1/student/payments/'.$p->id)->assertJsonPath('data.financial_status', 'partially_refunded')->assertJsonPath('data.refunded_amount', '70.00');
        $this->postJson('/api/v1/student/refunds', ['payment_id' => $p->id, 'account_id' => 'A'])->assertConflict();
        $this->assertSame('succeeded', $p->fresh()->status);
    }

    public function test_rejected_refund_restores_access_and_allows_new_request(): void
    {
        $p = $this->submit();
        $this->approve($p);
        $id = $this->refund($p);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/refunds/'.$id.'/review', ['decision' => 'reject', 'review_note' => 'Incorrect account', 'rejection_code' => 'rejected_invalid_data'])->assertOk();
        Sanctum::actingAs($this->studentUser);
        $this->getJson('/api/v1/contents/'.$this->video->id.'/assets')->assertOk();
        $new = $this->refund($p);
        $this->assertNotSame($id, $new);
    }

    public function test_refund_proof_is_private_and_foreign_payments_are_not_exposed(): void
    {
        $p = $this->submit();
        $this->approve($p);
        $id = $this->refund($p);
        $this->approveRefund($id);
        $url = $this->finish($id)->assertOk()->json('data.refund_proof_url');
        $other = User::factory()->create();
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/student/refunds/eligibility?payment_id='.$p->id)->assertNotFound();
        $this->get($url)->assertNotFound();
        $this->getJson('/api/v1/student/refunds/'.$id)->assertNotFound();
    }

    public function test_refund_completion_rolls_back_database_and_file_when_audit_fails(): void
    {
        $p = $this->submit();
        $this->approve($p);
        $id = $this->refund($p);
        $this->approveRefund($id);
        AuditLog::creating(fn () => throw new \RuntimeException('audit failed'));
        try {
            $this->finish($id)->assertStatus(500);
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseHas('refund_requests', ['id' => $id, 'status' => 'APPROVED', 'refund_proof_path' => null]);
        $this->assertSame([], Storage::disk('local')->allFiles('refund-proofs'));
    }

    public function test_teacher_suspension_creates_refund_review_for_unconsumed_balance_and_requires_payout_details(): void
    {
        $p = $this->submit();
        $this->approve($p);
        $this->postJson('/api/v1/student/contents/'.$this->video->id.'/complete')->assertOk();
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/admin/users/'.$this->teacherUser->id.'/suspend')->assertOk();
        $r = RefundRequest::where('payment_id', $p->id)->firstOrFail();
        $this->assertSame('70.00', $r->amount);
        $this->assertTrue($r->payout_details_required);
        Sanctum::actingAs($this->studentUser);
        $this->patchJson('/api/v1/student/refunds/'.$r->id.'/payout-details', ['account_id' => 'ACCOUNT'])->assertOk();
        $this->getJson('/api/v1/student/notifications')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_physical_checkout_is_rejected_and_teacher_approval_claims_last_seat(): void
    {
        $this->postJson('/api/v1/payments/checkout', ['service_type' => 'lesson_booking', 'service_id' => 1])->assertUnprocessable();
        $g = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'Group', 'capacity' => 1, 'status' => 'active', 'session_price' => 50, 'teaching_scope_type' => 'lesson', 'teaching_scope_id' => $this->first->id]);
        $id = $this->postJson('/api/v1/student/groups/'.$g->id.'/join-request')->assertCreated()->assertJsonPath('data.join_request.status', 'pending')->assertJsonPath('data.requires_teacher_approval', true)->json('data.join_request.id');
        $this->assertDatabaseCount('group_students', 0);
        Sanctum::actingAs($this->teacherUser);
        $this->postJson('/api/v1/teacher/groups/'.$g->id.'/join-requests/'.$id.'/approve')->assertOk();
        $other = User::factory()->create();
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/student/groups/'.$g->id.'/join')->assertConflict();
        $this->getJson('/api/v1/student/groups/'.$g->id.'/members')->assertOk()->assertJsonPath('data.members_count', 1)->assertJsonMissingPath('data.members.0.phone');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('group_students', 1);
    }

    public function test_individual_offer_books_a_cash_slot_once_without_enrollment_or_payment(): void
    {
        $offer = IndividualOffer::create(['teacher_subject_id' => $this->course->id, 'teaching_scope_type' => 'unit', 'teaching_scope_id' => $this->unit->id, 'title' => 'Unit tutoring', 'session_price' => 25, 'status' => 'active']);
        $a = TeacherAvailability::create(['teacher_id' => $this->teacher->id, 'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'recurrence_type' => 'none', 'timezone' => 'UTC', 'status' => 'active']);
        $body = ['offer_id' => $offer->id, 'availability_id' => $a->id, 'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'ends_at' => now()->addDay()->setTime(11, 0)->toIso8601String()];
        $this->postJson('/api/v1/student/individual-bookings', $body)->assertCreated()->assertJsonPath('data.payment_method', 'cash_to_teacher')->assertJsonPath('data.session.type', 'individual');
        $other = User::factory()->create();
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/student/individual-bookings', $body)->assertUnprocessable();
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseCount('individuals', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_lesson_purchase_only_opens_that_lesson(): void
    {
        $p = $this->submit('lesson', $this->first->id);
        $this->approve($p);
        $this->getJson('/api/v1/contents/'.$this->video->id.'/assets')->assertOk();
        $this->getJson('/api/v1/contents/'.$this->other->id.'/assets')->assertNotFound();
    }

    public function test_simple_quiz_is_free_limited_graded_and_counts_with_lower_weight(): void
    {
        $c = Content::create(['lesson_id' => $this->first->id, 'type' => 'quiz', 'title' => 'Quick quiz', 'status' => 'published']);
        $q = Quiz::create(['content_id' => $c->id, 'quiz_type' => 'simple', 'attempts_allowed' => 1, 'status' => 'published', 'progress_weight' => 0.30]);
        $c->update(['price' => 10]);
        $this->postJson('/api/v1/payments/checkout', ['service_type' => 'content', 'service_id' => $c->id])->assertUnprocessable()->assertJsonPath('code', 'SIMPLE_QUIZ_FREE');
        $question = Question::create(['quiz_id' => $q->id, 'type' => 'mcq', 'body' => '2+2?', 'points' => 1, 'rubric_json' => ['options' => ['3', '4'], 'correct_answer' => '4']]);
        $res = $this->getJson('/api/v1/quizzes/'.$q->id.'/data')->assertOk()->assertJsonPath('data.protected', false);
        $this->assertStringNotContainsString('correct_answer', $res->getContent());
        $id = $this->postJson('/api/v1/quizzes/'.$q->id.'/attempts')->assertCreated()->json('data.attempt_id');
        $this->postJson('/api/v1/quiz-attempts/'.$id.'/answers', ['question_id' => $question->id, 'answer_text' => '4'])->assertCreated();
        $this->postJson('/api/v1/quiz-attempts/'.$id.'/submit')->assertOk();
        $this->postJson('/api/v1/quizzes/'.$q->id.'/attempts')->assertUnprocessable();
        $this->getJson('/api/v1/student/teacher-subjects/'.$this->course->id.'/learning-summary')->assertOk()->assertJsonPath('data.earned_progress_points', 0.3)->assertJsonPath('data.completed_lessons', 0);
        $this->getJson('/api/v1/contents/'.$this->video->id.'/assets')->assertNotFound();
    }

    public function test_old_payments_without_price_snapshot_require_audited_reconciliation_not_guessed_prices(): void
    {
        $e = Enrollment::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $this->course->id, 'price' => 100, 'status' => 'active']);
        $p = Payment::create(['payer_id' => $this->studentUser->id, 'payable_type' => $e->getMorphClass(), 'payable_id' => $e->id, 'amount' => 100, 'currency' => 'SYP', 'provider' => 'sham_cash', 'status' => 'succeeded']);
        $this->getJson('/api/v1/student/refunds/eligibility?payment_id='.$p->id)->assertJsonPath('data.code', 'PRICING_SNAPSHOT_REQUIRED');
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/payments/'.$p->id.'/pricing-allocation', ['review_note' => 'Original invoice confirmed', 'lessons' => [
            ['lesson_id' => $this->first->id, 'lesson_price_snapshot' => 60, 'allocated_amount' => 30, 'content_ids_snapshot' => [$this->video->id]],
            ['lesson_id' => $this->second->id, 'lesson_price_snapshot' => 140, 'allocated_amount' => 70, 'content_ids_snapshot' => [$this->other->id]],
        ]])->assertOk()->assertJsonPath('data.refundable_amount', '100.00');
    }

    public function test_teacher_reviews_submitted_essay_and_recomputes_attempt_score(): void
    {
        $c = Content::create(['lesson_id' => $this->first->id, 'type' => 'quiz', 'title' => 'Essay', 'status' => 'published']);
        $q = Quiz::create(['content_id' => $c->id, 'quiz_type' => 'simple', 'attempts_allowed' => 1, 'status' => 'published']);
        $question = Question::create(['quiz_id' => $q->id, 'type' => 'essay', 'body' => 'Explain', 'points' => 10]);
        $id = $this->postJson('/api/v1/quizzes/'.$q->id.'/attempts')->assertCreated()->json('data.attempt_id');
        $this->postJson('/api/v1/quiz-attempts/'.$id.'/answers', ['question_id' => $question->id, 'answer_text' => 'My answer'])->assertCreated();
        $this->postJson('/api/v1/quiz-attempts/'.$id.'/submit')->assertOk();
        $answer = Answer::where('attempt_id', $id)->firstOrFail();
        Sanctum::actingAs($this->teacherUser);
        $this->postJson('/api/v1/teacher/answers/'.$answer->id.'/grade', ['score' => 11])->assertUnprocessable();
        $this->postJson('/api/v1/teacher/answers/'.$answer->id.'/grade', ['score' => 8])->assertOk()->assertJsonPath('data.attempt.status', 'graded')->assertJsonPath('data.attempt.score', '8.00');
    }
}
