<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\PaidBooking;
use App\Models\Payment;
use App\Models\PaymentSetting;
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

    private User $user;

    private User $admin;

    private Teacher $teacher;

    private TeacherSubject $course;

    private TeacherAvailability $availability;

    private Content $content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(8));
        Storage::fake('local');
        Storage::disk('local')->put('qr.png', 'demo');
        PaymentSetting::find(1)->update(['account_id' => 'DEMO', 'qr_path' => 'qr.png']);
        $this->user = $this->student();
        $this->admin = User::factory()->create(['role' => 'company_admin', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->course = TeacherSubject::create(['teacher_id' => $this->teacher->id, 'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id,
            'price' => 100, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $this->course->id, 'title' => 'Unit', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson', 'status' => 'published']);
        $this->content = Content::create(['lesson_id' => $lesson->id, 'title' => 'Content', 'type' => 'video', 'status' => 'published']);
        $this->availability = $this->availability(3);
        Sanctum::actingAs($this->user);
    }

    private function student(): User
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    private function availability(int $days): TeacherAvailability
    {
        return TeacherAvailability::create(['teacher_id' => $this->teacher->id, 'date' => now()->addDays($days)->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'timezone' => 'UTC', 'recurrence_type' => 'none', 'status' => 'active', 'price' => 50]);
    }

    private function interval(?TeacherAvailability $availability = null): array
    {
        $availability ??= $this->availability;

        return ['starts_at' => $availability->date->copy()->setTime(10, 0)->toIso8601String(),
            'ends_at' => $availability->date->copy()->setTime(11, 0)->toIso8601String()];
    }

    private function submit(string $reference = 'IN-1', ?TeacherAvailability $availability = null): int
    {
        $availability ??= $this->availability;
        $checkout = $this->postJson('/api/v1/payments/checkout', ['service_type' => 'lesson_booking', 'service_id' => $availability->id] + $this->interval($availability))
            ->assertCreated()->json('data.checkout_id');

        return $this->postJson('/api/v1/student/payments', ['checkout_id' => $checkout, 'provider_reference' => $reference,
            'proof' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf'), 'amount' => 1, 'status' => 'succeeded'])
            ->assertCreated()->assertJsonPath('data.amount', '50.00')->assertJsonPath('data.payment_status', 'PENDING_REVIEW')
            ->assertJsonPath('data.booking.status', 'HELD')->json('data.id');
    }

    private function approve(int $payment): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/payments/'.$payment.'/review', ['decision' => 'confirm'])->assertOk()->assertJsonPath('data.payment.payment_status', 'APPROVED');
        Sanctum::actingAs($this->user);
    }

    private function requestRefund(int $payment, string $code = 'exceptional'): int
    {
        return $this->postJson('/api/v1/refunds', ['payment_id' => $payment, 'account_id' => 'STUDENT-ACCOUNT',
            'reason_code' => $code, 'reason' => 'Please refund this payment.', 'amount' => 1, 'status' => 'COMPLETED'])
            ->assertCreated()->assertJsonPath('data.status', 'PENDING_REVIEW')->json('data.id');
    }

    public function test_checkout_creates_no_hold_and_submission_starts_a_full_24_hour_hold(): void
    {
        $this->postJson('/api/v1/payments/checkout', ['service_type' => 'lesson_booking', 'service_id' => $this->availability->id] + $this->interval())->assertCreated();
        $this->assertDatabaseCount('paid_bookings', 0);
        $id = $this->submit();
        $booking = PaidBooking::where('payment_id', $id)->firstOrFail();
        $this->assertTrue($booking->hold_expires_at->eq(now()->addHours(24)));
        $this->assertSame('pending', $booking->enrollment->status);
        $this->assertSame('paid_booking', $booking->enrollment->enrollable_type);
        $this->assertDatabaseCount('sessions', 0);
        $other = $this->student();
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/payments/checkout', ['service_type' => 'lesson_booking', 'service_id' => $this->availability->id] + $this->interval())
            ->assertConflict()->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $this->getJson('/api/v1/teachers/'.$this->teacher->id.'/paid-slots')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student/paid-bookings/'.$booking->id)->assertNotFound();
    }

    public function test_expiry_frees_interval_without_deciding_payment_even_before_scheduler_runs(): void
    {
        $id = $this->submit();
        $this->travel(24)->hours();
        $this->getJson('/api/v1/student/paid-bookings')->assertOk()->assertJsonPath('data.0.status', 'EXPIRED');
        $this->getJson('/api/v1/teachers/'.$this->teacher->id.'/paid-slots')->assertOk()->assertJsonCount(1, 'data');
        $this->artisan('payments:expire-holds')->assertSuccessful();
        $this->assertDatabaseHas('paid_bookings', ['payment_id' => $id, 'status' => 'EXPIRED']);
        $this->assertDatabaseHas('payments', ['id' => $id, 'status' => 'pending']);
        $this->artisan('payments:expire-holds')->assertSuccessful();
        $this->assertSame(1, AuditLog::where('action', 'booking.hold_expired')->count());
    }

    public function test_approval_confirms_only_lesson_and_does_not_unlock_course_or_allow_extra_bookings(): void
    {
        $id = $this->submit();
        $this->approve($id);
        $booking = PaidBooking::where('payment_id', $id)->firstOrFail();
        $this->assertSame('CONFIRMED', $booking->status);
        $this->assertSame('scheduled', $booking->session->status);
        $this->getJson('/api/v1/student/bookings')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contents/'.$this->content->id.'/assets')->assertNotFound();
        $this->postJson('/api/v1/teacher-availabilities/'.$this->availability->id.'/book', ['enrollment_id' => $booking->enrollment->id] + $this->interval())
            ->assertConflict();
        Sanctum::actingAs($this->teacher->user);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'INDIVIDUAL', 'enrollment_id' => $booking->enrollment->id] + $this->interval())
            ->assertUnprocessable();
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/payments/'.$id.'/review', ['decision' => 'confirm'])->assertConflict();
        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_rejection_releases_hold_preserves_attempt_and_allows_new_submission(): void
    {
        $id = $this->submit();
        Sanctum::actingAs($this->admin);
        $url = '/api/v1/admin/payments/'.$id.'/review';
        $this->postJson($url, ['decision' => 'reject'])->assertUnprocessable();
        $this->postJson($url, ['decision' => 'reject', 'rejection_reason' => 'No transfer'])->assertOk()->assertJsonPath('data.booking.status', 'REJECTED');
        Sanctum::actingAs($this->user);
        $this->submit('IN-2');
        $this->assertDatabaseCount('paid_bookings', 2);
        $this->assertDatabaseHas('payments', ['id' => $id, 'status' => 'failed']);
    }

    public function test_late_approval_rechecks_availability_and_can_confirm_a_still_free_interval(): void
    {
        $id = $this->submit();
        $this->travel(25)->hours();
        $this->artisan('payments:expire-holds')->assertSuccessful();
        $this->approve($id);
        $this->assertDatabaseHas('paid_bookings', ['payment_id' => $id, 'status' => 'CONFIRMED']);
        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_late_approval_with_conflict_requires_alternative_and_reschedule_is_atomic(): void
    {
        $id = $this->submit();
        $this->travel(25)->hours();
        $other = $this->student();
        Sanctum::actingAs($other);
        $this->submit('OTHER-IN');
        $this->approve($id);
        $booking = PaidBooking::where('payment_id', $id)->firstOrFail();
        $this->assertSame('NEEDS_RESCHEDULE', $booking->status);
        $this->assertSame('pending', $booking->enrollment->status);
        $this->assertDatabaseCount('sessions', 0);
        $alternative = $this->availability(4);
        $url = '/api/v1/student/paid-bookings/'.$booking->id.'/reschedule';
        $this->postJson($url, ['availability_id' => $this->availability->id] + $this->interval())->assertConflict();
        $this->postJson($url, ['availability_id' => $alternative->id] + $this->interval($alternative))->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
        $this->postJson($url, ['availability_id' => $alternative->id] + $this->interval($alternative))->assertConflict();
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseHas('payments', ['id' => $id, 'amount' => 50, 'status' => 'succeeded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'booking.rescheduled', 'entity_id' => $booking->id]);
    }

    public function test_teacher_cancellation_allows_manual_refund_with_unchanged_original_payment(): void
    {
        $id = $this->submit();
        $this->approve($id);
        $original = Payment::find($id)->toArray();
        $booking = PaidBooking::where('payment_id', $id)->firstOrFail();
        Sanctum::actingAs($this->teacher->user);
        $this->postJson('/api/v1/teacher/sessions/'.$booking->session_id.'/cancel')->assertOk();
        $this->assertDatabaseHas('paid_bookings', ['id' => $booking->id, 'status' => 'CANCELLED', 'cancelled_by_role' => 'teacher']);
        Sanctum::actingAs($this->user);
        $refund = $this->requestRefund($id, 'teacher_cancelled');
        $this->getJson('/api/v1/refunds/my')->assertOk()->assertJsonPath('data.0.amount', '50.00');
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'approve', 'review_note' => 'Teacher cancellation verified'])->assertOk();
        $this->assertDatabaseHas('refund_requests', ['id' => $refund, 'status' => 'APPROVED', 'completed_at' => null]);
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/complete', ['transfer_reference' => 'OUT-1'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertSame($original, Payment::find($id)->toArray());
        $this->assertDatabaseHas('paid_bookings', ['id' => $booking->id, 'status' => 'REFUNDED']);
        $this->assertDatabaseHas('refund_requests', ['id' => $refund, 'completed_by' => $this->admin->id]);
        Sanctum::actingAs($this->user);
        $this->getJson('/api/v1/student/payments/'.$id)->assertOk()->assertJsonPath('data.payment_status', 'APPROVED')
            ->assertJsonPath('data.refund.status', 'COMPLETED')->assertJsonPath('data.can_request_refund', false);
    }

    public function test_pending_or_unowned_payments_cannot_be_refunded_and_admin_actions_are_protected(): void
    {
        $id = $this->submit();
        $body = ['payment_id' => $id, 'account_id' => 'ACCOUNT', 'reason_code' => 'exceptional', 'reason' => 'Please refund'];
        $this->postJson('/api/v1/refunds', $body)->assertConflict()->assertJsonPath('code', 'REFUND_NOT_ALLOWED');
        $this->approve($id);
        $refund = $this->requestRefund($id);
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'approve', 'review_note' => 'anything'])->assertForbidden();
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/complete', ['transfer_reference' => 'OUT'])->assertForbidden();
        Sanctum::actingAs($this->student());
        $this->postJson('/api/v1/refunds', $body)->assertNotFound();
        $this->getJson('/api/v1/refunds/'.$refund)->assertNotFound();
        $this->getJson('/api/v1/refunds/my?user_id='.$this->user->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/refunds')->assertForbidden();
    }

    public function test_refund_rejection_preserves_history_and_allows_a_new_request(): void
    {
        $id = $this->submit();
        $this->approve($id);
        $refund = $this->requestRefund($id);
        $body = ['payment_id' => $id, 'account_id' => 'ACCOUNT', 'reason_code' => 'exceptional', 'reason' => 'Again'];
        $this->postJson('/api/v1/refunds', $body)->assertConflict()->assertJsonPath('code', 'REFUND_ALREADY_EXISTS');
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'reject'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'reject', 'review_note' => 'Not eligible'])->assertOk();
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'approve', 'review_note' => 'Again'])->assertConflict();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/refunds', $body)->assertCreated();
        $this->assertDatabaseCount('refund_requests', 2);
        $this->assertDatabaseHas('refund_requests', ['id' => $refund, 'status' => 'REJECTED', 'active_payment_id' => null]);
    }

    public function test_completion_requires_manual_approval_and_distinct_actual_transfer_and_cannot_repeat(): void
    {
        $id = $this->submit();
        $this->approve($id);
        $refund = $this->requestRefund($id);
        Sanctum::actingAs($this->admin);
        $url = '/api/v1/admin/refunds/'.$refund.'/complete';
        $this->postJson($url, ['transfer_reference' => 'OUT'])->assertConflict();
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'approve', 'review_note' => 'Exception approved'])->assertOk();
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['transfer_reference' => 'IN-1'])->assertUnprocessable();
        $this->postJson($url, ['transfer_reference' => 'OUT'])->assertOk();
        $this->postJson($url, ['transfer_reference' => 'OUT-OTHER'])->assertConflict();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/refunds', ['payment_id' => $id, 'account_id' => 'ACCOUNT', 'reason_code' => 'exceptional', 'reason' => 'Again'])->assertConflict();
    }

    public function test_active_refund_blocks_rescheduling_and_rejection_restores_the_option(): void
    {
        $id = $this->submit();
        $this->availability->update(['status' => 'disabled']);
        $this->approve($id);
        $booking = PaidBooking::where('payment_id', $id)->firstOrFail();
        $refund = $this->requestRefund($id, 'service_unavailable');
        $alt = $this->availability(4);
        $url = '/api/v1/student/paid-bookings/'.$booking->id.'/reschedule';
        $body = ['availability_id' => $alt->id] + $this->interval($alt);
        $this->postJson($url, $body)->assertConflict()->assertJsonPath('code', 'RESCHEDULE_NOT_ALLOWED');
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'reject', 'review_note' => 'Alternative agreed'])->assertOk();
        Sanctum::actingAs($this->user);
        $this->postJson($url, $body)->assertOk();
    }

    public function test_system_reason_requires_matching_state_and_partial_slots_are_not_underpriced(): void
    {
        $interval = $this->interval();
        $interval['ends_at'] = $this->availability->date->copy()->setTime(10, 30)->toIso8601String();
        $this->postJson('/api/v1/payments/checkout', ['service_type' => 'lesson_booking', 'service_id' => $this->availability->id] + $interval)
            ->assertConflict()->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        $id = $this->submit();
        $this->approve($id);
        $refund = $this->requestRefund($id, 'teacher_cancelled');
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'approve', 'review_note' => 'Claim'])->assertConflict();
        $this->assertDatabaseHas('refund_requests', ['id' => $refund, 'status' => 'PENDING_REVIEW']);
    }

    public function test_material_refund_uses_original_amount_revokes_access_and_preserves_payment(): void
    {
        $checkout = $this->postJson('/api/v1/payments/checkout', ['service_type' => 'teacher_subject', 'service_id' => $this->course->id])->assertCreated()->json('data.checkout_id');
        $id = $this->postJson('/api/v1/student/payments', ['checkout_id' => $checkout, 'provider_reference' => 'COURSE-IN',
            'proof' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf')])->assertCreated()->json('data.id');
        $this->approve($id);
        $this->getJson('/api/v1/contents/'.$this->content->id.'/assets')->assertOk();
        $this->course->update(['price' => 500]);
        $refund = $this->requestRefund($id, 'service_unavailable');
        $this->assertDatabaseHas('refund_requests', ['id' => $refund, 'amount' => 100, 'currency' => 'SYP']);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'approve', 'review_note' => 'Service failure verified'])->assertOk();
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/complete', ['transfer_reference' => 'COURSE-OUT'])->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $id, 'status' => 'succeeded', 'amount' => 100]);
        Sanctum::actingAs($this->user);
        $this->getJson('/api/v1/contents/'.$this->content->id.'/assets')->assertNotFound();
    }

    public function test_refund_completion_rolls_back_if_audit_write_fails(): void
    {
        $id = $this->submit();
        $this->approve($id);
        $refund = $this->requestRefund($id);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'approve', 'review_note' => 'Exception approved'])->assertOk();
        AuditLog::creating(function () {
            throw new \RuntimeException('audit failure');
        });
        try {
            $this->postJson('/api/v1/admin/refunds/'.$refund.'/complete', ['transfer_reference' => 'OUT'])->assertStatus(500);
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseHas('refund_requests', ['id' => $refund, 'status' => 'APPROVED', 'transfer_reference' => null]);
        $this->assertDatabaseHas('payments', ['id' => $id, 'status' => 'succeeded']);
        $this->assertDatabaseMissing('paid_bookings', ['payment_id' => $id, 'status' => 'REFUNDED']);
    }

    public function test_holds_are_respected_by_existing_booking_endpoints_and_calendar(): void
    {
        $this->submit();
        $other = $this->student();
        $enrollment = Enrollment::create(['student_id' => $other->student->id, 'teacher_id' => $this->teacher->id,
            'enrollable_type' => 'teacher_subject', 'enrollable_id' => $this->course->id, 'price' => 100, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/teacher-availabilities/'.$this->availability->id.'/book', ['enrollment_id' => $enrollment->id] + $this->interval())->assertConflict();
        $this->getJson('/api/v1/teachers/'.$this->teacher->id.'/available-slots')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/teachers/'.$this->teacher->id.'/available-sessions')->assertOk()->assertJsonCount(0, 'data');
        $calendar = $this->getJson('/api/v1/teachers/'.$this->teacher->id.'/calendar')->assertOk()->json('data');
        $this->assertNotEmpty(array_filter($calendar, fn ($period) => $period['reason'] === 'booked'));
        foreach ($calendar as $period) {
            $this->assertArrayNotHasKey('payment_id', $period);
            $this->assertArrayNotHasKey('student_id', $period);
        }
    }

    public function test_non_utc_hold_is_hidden_in_existing_availability_reads(): void
    {
        $this->availability->update(['timezone' => 'Asia/Damascus']);
        $start = $this->availability->date->toDateString().'T10:00:00+03:00';
        $end = $this->availability->date->toDateString().'T11:00:00+03:00';
        $checkout = $this->postJson('/api/v1/payments/checkout', ['service_type' => 'lesson_booking', 'service_id' => $this->availability->id,
            'starts_at' => $start, 'ends_at' => $end])->assertCreated()->json('data.checkout_id');
        $this->postJson('/api/v1/student/payments', ['checkout_id' => $checkout, 'provider_reference' => 'TZ-IN',
            'proof' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf')])->assertCreated();
        $this->assertSame('07:00:00', PaidBooking::first()->starts_at->format('H:i:s'));
        Enrollment::create(['student_id' => $this->user->student->id, 'teacher_id' => $this->teacher->id,
            'enrollable_type' => 'teacher_subject', 'enrollable_id' => $this->course->id, 'status' => 'active']);
        $this->getJson('/api/v1/teachers/'.$this->teacher->id.'/available-sessions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_refund_transfer_reference_cannot_be_reused_for_another_payment(): void
    {
        foreach ([$this->availability, $this->availability(4)] as $index => $availability) {
            Sanctum::actingAs($this->user);
            $id = $this->submit('IN-'.$index, $availability);
            $this->approve($id);
            $refund = $this->requestRefund($id);
            Sanctum::actingAs($this->admin);
            $this->postJson('/api/v1/admin/refunds/'.$refund.'/review', ['decision' => 'approve', 'review_note' => 'Manual exception approved'])->assertOk();
            $response = $this->postJson('/api/v1/admin/refunds/'.$refund.'/complete', ['transfer_reference' => 'SAME-OUT']);
            if ($index === 0) {
                $response->assertOk();
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors('transfer_reference');
                $this->assertDatabaseHas('refund_requests', ['id' => $refund, 'status' => 'APPROVED']);
            }
        }
    }
}
