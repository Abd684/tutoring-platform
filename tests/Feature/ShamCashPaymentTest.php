<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Models\PaymentSetting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShamCashPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $admin;

    private TeacherSubject $course;

    private Content $content;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $this->student->id, 'status' => 'active']);
        $this->admin = User::factory()->create(['role' => 'company_admin', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->course = TeacherSubject::create(['teacher_id' => $teacher->id,
            'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id, 'price' => 100, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $this->course->id, 'title' => 'Unit', 'price' => 50, 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson', 'status' => 'published']);
        $this->content = Content::create(['lesson_id' => $lesson->id, 'title' => 'Video', 'type' => 'video', 'price' => 20, 'status' => 'published']);
        Storage::disk('local')->put('payment-qr/qr.png', 'qr');
        PaymentSetting::findOrFail(1)->update(['account_id' => 'PLATFORM-ACCOUNT', 'qr_path' => 'payment-qr/qr.png']);
        Sanctum::actingAs($this->student);
    }

    private function checkout(): string
    {
        return $this->postJson('/api/v1/payments/checkout', ['service_type' => 'teacher_subject', 'service_id' => $this->course->id])
            ->assertCreated()->assertJsonPath('data.amount', '100.00')->json('data.checkout_id');
    }

    private function submit(string $checkout, string $reference = 'TRANSFER-1')
    {
        return $this->postJson('/api/v1/student/payments', [
            'checkout_id' => $checkout, 'provider_reference' => $reference,
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
            'amount' => 1, 'status' => 'succeeded', 'payer_id' => $this->admin->id,
        ]);
    }

    public function test_checkout_does_not_create_payment_or_enrollment_and_is_unique(): void
    {
        $first = $this->checkout();
        $this->assertNotSame($first, $this->checkout());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertEqualsWithDelta(30, now()->diffInMinutes(PaymentCheckout::find($first)->expires_at, false), 0.1);
    }

    public function test_proof_review_and_real_content_access_follow_server_amount(): void
    {
        $checkout = $this->checkout();
        $id = $this->submit($checkout)->assertCreated()->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.currency', 'SYP')->assertJsonPath('data.payer_id', $this->student->id)
            ->assertJsonPath('data.payment_status', 'PENDING_REVIEW')->assertJsonMissingPath('data.proof_path')->json('data.id');
        $payment = Payment::findOrFail($id);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->assertSame('pending', Enrollment::find($payment->payable_id)->status);
        $this->getJson('/api/v1/contents/'.$this->content->id.'/assets')->assertNotFound();
        $this->get('/api/v1/payment-proofs/'.$id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/api/v1/admin/payments/'.$id.'/review', ['decision' => 'confirm'])->assertForbidden();
        Sanctum::actingAs($this->admin);
        // A later catalog price must not change the submitted amount or prevent approval.
        $this->course->update(['price' => 0]);
        $review = '/api/v1/admin/payments/'.$id.'/review';
        $this->postJson($review, ['decision' => 'confirm'])->assertOk()
            ->assertJsonPath('data.payment.payment_status', 'APPROVED')->assertJsonPath('data.enrollment.status', 'active');
        $this->assertDatabaseHas('payments', ['id' => $id, 'amount' => 100, 'reviewed_by' => $this->admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.confirm', 'entity_id' => $id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->student->id, 'type' => 'payment_approved', 'related_payment_id' => $id]);
        $this->postJson($review, ['decision' => 'confirm'])->assertConflict();
        $this->postJson($review, ['decision' => 'reject', 'rejection_reason' => 'late'])->assertConflict();
        $this->assertDatabaseCount('notifications', 1);
        Sanctum::actingAs($this->student);
        $this->getJson('/api/v1/contents/'.$this->content->id.'/assets')->assertOk();
        $this->getJson('/api/v1/student/payments/'.$id)->assertOk()->assertJsonPath('data.payment_status', 'APPROVED');
        $this->getJson('/api/v1/student/notifications')->assertOk()->assertJsonPath('data.0.related_payment_id', $id);
    }

    public function test_missing_proof_invalid_mime_and_oversized_files_are_rejected(): void
    {
        $checkout = $this->checkout();
        $base = ['checkout_id' => $checkout, 'provider_reference' => 'TRANSFER'];
        $this->postJson('/api/v1/student/payments', $base)->assertUnprocessable()->assertJsonValidationErrors('proof');
        $spoofed = UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo 1;');
        $this->postJson('/api/v1/student/payments', $base + ['proof' => new UploadedFile($spoofed->getPathname(), 'fake.pdf', 'application/pdf', null, true)])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->postJson('/api/v1/student/payments', $base + ['proof' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('payment-proofs'));
    }

    public function test_checkout_expiration_and_price_change_require_new_checkout(): void
    {
        $checkout = $this->checkout();
        $this->course->update(['price' => 150]);
        $this->submit($checkout)->assertConflict()->assertJsonPath('code', 'PRICE_CHANGED');
        $this->course->update(['price' => 100]);
        $this->travel(31)->minutes();
        $this->submit($checkout)->assertConflict()->assertJsonPath('code', 'CHECKOUT_EXPIRED');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_repeated_submission_is_idempotent_and_other_checkouts_are_blocked(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();
        $id = $this->submit($first)->assertCreated()->json('data.id');
        $this->submit($first)->assertOk()->assertJsonPath('data.id', $id);
        $this->submit($first, 'ANOTHER')->assertConflict()->assertJsonPath('code', 'CHECKOUT_CONSUMED');
        $this->submit($second, 'SECOND')->assertConflict()->assertJsonPath('code', 'PAYMENT_PENDING');
        $this->postJson('/api/v1/payments/checkout', ['service_type' => 'teacher_subject', 'service_id' => $this->course->id])
            ->assertConflict()->assertJsonPath('code', 'PAYMENT_PENDING');
        $this->assertDatabaseCount('payments', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('payment-proofs'));
    }

    public function test_rejection_requires_reason_and_retry_preserves_history(): void
    {
        $id = $this->submit($this->checkout())->assertCreated()->json('data.id');
        Sanctum::actingAs($this->admin);
        $url = '/api/v1/admin/payments/'.$id.'/review';
        $this->postJson($url, ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        $this->postJson($url, ['decision' => 'reject', 'rejection_reason' => 'Transfer not received'])->assertOk()
            ->assertJsonPath('data.payment.payment_status', 'REJECTED')->assertJsonPath('data.enrollment.status', 'pending');
        $this->postJson($url, ['decision' => 'confirm'])->assertConflict();
        Sanctum::actingAs($this->student);
        $retry = $this->checkout();
        $this->submit($retry)->assertConflict()->assertJsonPath('code', 'TRANSFER_ALREADY_USED');
        $this->submit($retry, 'NEW-TRANSFER')->assertCreated();
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseHas('payments', ['id' => $id, 'status' => 'failed', 'rejection_reason' => 'Transfer not received']);
    }

    public function test_other_students_cannot_access_payment_proof_checkout_or_history(): void
    {
        $checkout = $this->checkout();
        $id = $this->submit($checkout)->assertCreated()->json('data.id');
        $other = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->submit($checkout)->assertNotFound();
        $this->getJson('/api/v1/student/payments/'.$id)->assertForbidden();
        $this->getJson('/api/v1/payment-proofs/'.$id)->assertForbidden();
        $this->getJson('/api/v1/student/payments?payer_id='.$this->student->id)->assertOk()->assertJsonCount(0, 'data');
        $otherCheckout = $this->checkout();
        $this->submit($otherCheckout)->assertConflict()->assertJsonPath('code', 'TRANSFER_ALREADY_USED');
    }

    public function test_settings_are_admin_only_and_missing_configuration_blocks_checkout(): void
    {
        $this->getJson('/api/v1/payment-settings')->assertOk()->assertJsonPath('data.account_id', 'PLATFORM-ACCOUNT')
            ->assertJsonPath('data.proof_max_kb', 5120)->assertJsonMissingPath('data.qr_path');
        $this->get('/api/v1/payment-settings/qr')->assertOk();
        $this->putJson('/api/v1/admin/payment-settings', ['account_id' => 'HACK'])->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/v1/admin/payment-settings', ['account_id' => 'NEW-ACCOUNT'])->assertOk()->assertJsonPath('data.configured', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_settings.updated']);
        PaymentSetting::find(1)->update(['account_id' => null]);
        Sanctum::actingAs($this->student);
        $this->postJson('/api/v1/payments/checkout', ['service_type' => 'teacher_subject', 'service_id' => $this->course->id])
            ->assertStatus(503)->assertJsonPath('code', 'PAYMENT_NOT_CONFIGURED');
    }

    public function test_destination_account_is_snapshotted_when_checkout_starts(): void
    {
        $checkout = $this->checkout();
        Storage::disk('local')->put('payment-qr/new.png', 'new qr');
        PaymentSetting::find(1)->update(['account_id' => 'DIFFERENT-ACCOUNT', 'qr_path' => 'payment-qr/new.png']);
        $this->get('/api/v1/payments/checkout/'.$checkout.'/qr')->assertOk()->assertStreamedContent('qr');
        $this->submit($checkout)->assertCreated()->assertJsonPath('data.service_snapshot.account_id', 'PLATFORM-ACCOUNT');
    }

    public function test_qr_update_and_png_proof_work_as_multipart_requests(): void
    {
        Sanctum::actingAs($this->admin);
        $this->post('/api/v1/admin/payment-settings', ['_method' => 'PUT', 'account_id' => 'UPDATED',
            'qr_image' => UploadedFile::fake()->image('qr.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.account_id', 'UPDATED');
        Sanctum::actingAs($this->student);
        $this->post('/api/v1/student/payments', ['checkout_id' => $this->checkout(), 'provider_reference' => 'PNG-TRANSFER',
            'proof' => UploadedFile::fake()->image('proof.png')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.proof_mime', 'image/png');
    }

    public function test_failed_submission_cleans_private_file_and_rolls_back_enrollment(): void
    {
        $checkout = $this->checkout();
        Payment::creating(function () {
            throw new \RuntimeException('database unavailable');
        });
        try {
            $this->submit($checkout)->assertStatus(500);
        } finally {
            Payment::flushEventListeners();
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertNull(PaymentCheckout::find($checkout)->payment_id);
        $this->assertSame([], Storage::disk('local')->allFiles('payment-proofs'));
        $this->submit($checkout)->assertCreated();
    }

    public function test_non_string_transfer_and_legacy_proof_free_submission_are_rejected(): void
    {
        $this->postJson('/api/v1/student/payments', ['checkout_id' => $this->checkout(), 'provider_reference' => ['invalid'],
            'proof' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('provider_reference');
        $id = $this->postJson('/api/v1/teacher-subjects/'.$this->course->id.'/enroll')->assertCreated()->json('data.id');
        $this->postJson('/api/v1/student/enrollments/'.$id.'/payments', ['provider_reference' => 'WITHOUT-PROOF'])
            ->assertStatus(410)->assertJsonPath('code', 'CHECKOUT_REQUIRED');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_admin_lists_pending_and_crud_cannot_bypass_review_or_delete_attempts(): void
    {
        $id = $this->submit($this->checkout())->assertCreated()->json('data.id');
        $this->getJson('/api/v1/admin/payments')->assertForbidden();
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/payments?payment_status=PENDING_REVIEW')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/payments?payment_status=APPROVED')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/api/v1/payment-proofs/'.$id)->assertOk();
        $this->patchJson('/api/v1/payments/'.$id, ['status' => 'succeeded'])->assertUnprocessable();
        $this->deleteJson('/api/v1/payments/'.$id)->assertConflict();
        $this->postJson('/api/v1/payments', ['provider' => 'sham_cash'])->assertUnprocessable();
    }

    public function test_owned_service_is_blocked_and_unit_and_content_are_supported(): void
    {
        foreach (['unit' => $this->content->lesson->unit_id, 'content' => $this->content->id] as $type => $id) {
            $this->postJson('/api/v1/payments/checkout', ['service_type' => $type, 'service_id' => $id])
                ->assertCreated()->assertJsonPath('data.service.type', $type);
        }
        Enrollment::create(['student_id' => $this->student->student->id, 'teacher_id' => $this->course->teacher_id,
            'enrollable_type' => 'teacher_subject', 'enrollable_id' => $this->course->id, 'price' => 100, 'status' => 'active']);
        $this->postJson('/api/v1/payments/checkout', ['service_type' => 'content', 'service_id' => $this->content->id])
            ->assertConflict()->assertJsonPath('code', 'ALREADY_OWNED');
    }

    public function test_failed_review_rolls_back_payment_enrollment_and_audit(): void
    {
        $id = $this->submit($this->checkout())->assertCreated()->json('data.id');
        Sanctum::actingAs($this->admin);
        Notification::creating(function () {
            throw new \RuntimeException('notification persistence failed');
        });
        try {
            $this->postJson('/api/v1/admin/payments/'.$id.'/review', ['decision' => 'confirm'])->assertStatus(500);
        } finally {
            Notification::flushEventListeners();
        }
        $this->assertDatabaseHas('payments', ['id' => $id, 'status' => 'pending', 'reviewed_at' => null]);
        $this->assertSame('pending', Enrollment::find(Payment::find($id)->payable_id)->status);
        $this->assertSame(0, AuditLog::where('action', 'payment.confirm')->count());
    }
}
