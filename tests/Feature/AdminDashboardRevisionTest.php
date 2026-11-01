<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Governortate;
use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Individual;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\PaidBooking;
use App\Models\Payment;
use App\Models\PaymentLessonAllocation;
use App\Models\RefundRequest;
use App\Models\Region;
use App\Models\School;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDashboardRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'company_admin', 'status' => 'active']);
    }

    private function teacher(string $name = 'مدرس أول'): array
    {
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active', 'name' => $name]);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'مادة '.$name, 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'price' => 50, 'status' => 'active']);
        $group = Group::create(['teacher_subject_id' => $course->id, 'name' => 'مجموعة '.$name, 'capacity' => 5, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'وحدة '.$name, 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'درس '.$name, 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'title' => 'محتوى '.$name, 'type' => 'pdf', 'status' => 'published']);

        return compact('user', 'teacher', 'subject', 'course', 'group', 'unit', 'lesson', 'content');
    }

    private function student(): Student
    {
        $u = User::factory()->create(['role' => 'student', 'status' => 'active']);

        return Student::create(['user_id' => $u->id, 'status' => 'active']);
    }

    public function test_no_account_email_can_be_changed_by_an_admin_including_own_email(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $teacher = $this->teacher();
        $student = $this->student();
        foreach ([['teachers', $teacher['user']], ['students', $student->user]] as [$kind,$user]) {
            $data = ['name' => 'اسم لم يحفظ', 'phone' => $user->phone, 'status' => 'active', 'email' => 'forged@example.invalid'];
            $this->put('/admin/'.$kind.'/'.$user->id, $data)->assertSessionHasErrors('email');
            $this->assertNotSame($data['email'], $user->fresh()->email);
            $this->assertNotSame($data['name'], $user->fresh()->name);
            unset($data['email']);
            $this->put('/admin/'.$kind.'/'.$user->id, $data)->assertRedirect();
            $this->assertSame($user->email, $user->fresh()->email);
            $html = $this->get('/admin/'.$kind.'/'.$user->id.'/edit')->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/name="email"[^>]*disabled/', $html);
        }
        $old = $admin->email;
        $this->patch('/admin/settings/profile', ['name' => 'تعديل غير محفوظ', 'email' => 'admin-forged@example.invalid'])->assertSessionHasErrors('email');
        $this->assertSame($old, $admin->fresh()->email);
        $this->patch('/admin/settings/profile', ['name' => 'مدير معدل'])->assertRedirect();
        $this->assertSame($old, $admin->fresh()->email);
        $this->assertSame('مدير معدل', $admin->fresh()->name);
    }

    public function test_teacher_workspace_is_the_only_navigation_for_groups_and_sessions(): void
    {
        $a = $this->teacher('الرياضيات');
        $b = $this->teacher('الفيزياء');
        $this->actingAs($this->admin());
        $home = $this->get('/admin')->assertOk();
        $home->assertDontSee('href="http://localhost/admin/groups"', false)->assertDontSee('href="http://localhost/admin/sessions"', false);
        $this->get('/admin/groups')->assertRedirect('/admin/teachers');
        $this->get('/admin/sessions')->assertRedirect('/admin/teachers');
        $this->get('/admin/teachers/'.$a['user']->id)->assertOk()->assertSee('/admin/teachers/'.$a['user']->id.'/groups', false)->assertSee('/admin/teachers/'.$a['user']->id.'/sessions', false);
        $path = '/admin/teachers/'.$a['user']->id;
        $this->get($path.'/groups')->assertOk()->assertSee($a['group']->name)->assertDontSee($b['group']->name);
        $this->get($path.'/groups/create')->assertOk()->assertSee($a['subject']->name)->assertDontSee($b['subject']->name);
        $this->get($path.'/groups/'.$b['group']->id)->assertNotFound();
        $this->put($path.'/groups/'.$b['group']->id, ['name' => 'اختراق', 'teacher_subject_id' => $b['course']->id, 'capacity' => 9, 'status' => 'closed'])->assertNotFound();
        $this->post($path.'/groups', ['name' => 'غير مسموح', 'teacher_subject_id' => $b['course']->id, 'capacity' => 5, 'status' => 'active'])->assertNotFound();
        $this->post($path.'/groups', ['name' => 'مجموعة جديدة', 'teacher_subject_id' => $a['course']->id, 'capacity' => 5, 'status' => 'active'])->assertRedirect($path.'/groups');
        $this->assertSame('active', $b['group']->fresh()->status);
        $student = $this->student();
        $this->get('/admin/teachers/'.$student->user_id.'/groups')->assertNotFound();
        $this->post('/admin/groups', ['name' => 'غير مسموح'])->assertStatus(405);
    }

    public function test_session_scope_blocks_cross_teacher_reads_and_writes(): void
    {
        $a = $this->teacher('أ');
        $b = $this->teacher('ب');
        $this->actingAs($this->admin());
        $session = Session::create(['teacher_id' => $b['teacher']->id, 'type' => 'group', 'type_id' => $b['group']->id, 'status' => 'scheduled', 'capacity' => 5, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $base = '/admin/teachers/'.$a['user']->id.'/sessions';
        $this->get($base.'/'.$session->id)->assertNotFound();
        $this->post($base.'/'.$session->id.'/cancel')->assertNotFound();
        $this->post($base, ['teacher_id' => $b['teacher']->id, 'type_id' => $b['group']->id, 'type' => 'group', 'starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->addHour()->toIso8601String()])->assertNotFound();
        $this->get($base)->assertOk()->assertDontSee($b['group']->name);
        $this->assertSame('scheduled', $session->fresh()->status);
    }

    public function test_subject_catalog_saves_unique_codes_and_preserves_existing_offers(): void
    {
        $a = $this->teacher();
        $this->actingAs($this->admin());
        $this->put('/admin/subjects/'.$a['subject']->id, ['name' => $a['subject']->name, 'code' => 'math-01', 'description' => 'وصف', 'status' => 'inactive'])->assertRedirect();
        $this->assertDatabaseHas('subjects', ['id' => $a['subject']->id, 'code' => 'MATH-01', 'status' => 'inactive']);
        $this->assertDatabaseHas('teacher_subjects', ['id' => $a['course']->id]);
        $this->post('/admin/subjects', ['name' => 'مادة أخرى', 'code' => 'MATH-01', 'status' => 'active'])->assertSessionHasErrors('code');
        $this->get('/admin/subjects?edit='.$a['subject']->id)->assertOk()->assertSee('MATH-01');
    }

    public function test_geography_rejects_duplicates_and_reparenting_linked_records(): void
    {
        $this->actingAs($this->admin());
        $province = Governortate::create(['name' => 'دمشق']);
        $other = Governortate::create(['name' => 'حلب']);
        $this->post('/admin/geography', ['kind' => 'region', 'name' => 'المزة', 'governortate_id' => $province->id])->assertRedirect();
        $region = Region::where('name', 'المزة')->firstOrFail();
        $this->post('/admin/geography', ['kind' => 'region', 'name' => 'المزة', 'governortate_id' => $province->id])->assertSessionHasErrors('name');
        $this->post('/admin/geography', ['kind' => 'school', 'name' => 'ثانوية تجريبية', 'region_id' => $region->id, 'code' => 'sch-10', 'education_stage' => 'secondary'])->assertRedirect();
        $school = School::firstOrFail();
        $this->assertSame('SCH-10', $school->code);
        $this->assertSame('secondary', $school->education_stage);
        $this->put('/admin/geography/region/'.$region->id, ['name' => 'المزة', 'governortate_id' => $other->id])->assertSessionHasErrors('governortate_id');
        $r2 = Region::create(['name' => 'منطقة أخرى', 'governortate_id' => $province->id]);
        $student = $this->student();
        $student->update(['school_id' => $school->id]);
        $this->put('/admin/geography/school/'.$school->id, ['name' => $school->name, 'region_id' => $r2->id, 'code' => 'SCH-10'])->assertSessionHasErrors('region_id');
        $this->assertSame($region->id, $school->fresh()->region_id);
        $this->get('/admin/geography?kind=school&governortate_id='.$other->id.'&region_id='.$region->id)->assertUnprocessable();
    }

    public function test_academic_grant_has_no_payment_and_rejects_duplicate_and_unpublished_content(): void
    {
        $a = $this->teacher();
        $student = $this->student();
        $this->actingAs($this->admin());
        $data = ['student_id' => $student->id, 'target' => 'unit:'.$a['unit']->id, 'starts_at' => now()->toIso8601String(), 'expires_at' => now()->addMonth()->toIso8601String(), 'reason' => 'منحة تعليمية موثقة'];
        $this->post('/admin/enrollments', $data)->assertRedirect();
        $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'enrollable_type' => 'unit', 'enrollable_id' => $a['unit']->id, 'price' => 0, 'status' => 'active']);
        $this->assertDatabaseCount('payments', 0);
        $this->post('/admin/enrollments', $data)->assertSessionHasErrors('target');
        $a['lesson']->update(['status' => 'draft']);
        $this->post('/admin/enrollments', [...$data, 'target' => 'content:'.$a['content']->id])->assertSessionHasErrors('target');
        $this->post('/admin/enrollments', [...$data, 'target' => 'paid_booking:1'])->assertSessionHasErrors('target');
        $this->get('/admin/enrollments?enrollment_id='.Enrollment::firstOrFail()->id)->assertOk()->assertSee('التسجيل #'.Enrollment::firstOrFail()->id);
    }

    public function test_cancelling_free_grant_preserves_history_and_cannot_cancel_paid_access(): void
    {
        $a = $this->teacher();
        $student = $this->student();
        $this->actingAs($this->admin());
        $grant = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $a['teacher']->id, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $a['course']->id, 'price' => 0, 'status' => 'active']);
        $membership = GroupStudent::create(['group_id' => $a['group']->id, 'student_id' => $student->id, 'status' => 'active', 'joined_at' => now()]);
        $individual = Individual::create(['student_id' => $student->id, 'teacher_subject_id' => $a['course']->id, 'status' => 'active']);
        $session = Session::create(['teacher_id' => $a['teacher']->id, 'type_id' => $individual->id, 'type' => 'individual', 'status' => 'scheduled', 'capacity' => 1, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $this->post('/admin/enrollments/'.$grant->id.'/cancel', ['reason' => 'انتهت المنحة'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $grant->fresh()->status);
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertSame('scheduled', $session->fresh()->status);
        $grant = $grant->fresh();
        $grant->update(['status' => 'active', 'price' => 50]);
        $this->post('/admin/enrollments/'.$grant->id.'/cancel', ['reason' => 'غير مسموح'])->assertSessionHasErrors('reason');
        $this->assertSame('active', $grant->fresh()->status);
    }

    public function test_paid_booking_reschedule_uses_existing_financial_workflow(): void
    {
        $a = $this->teacher();
        $student = $this->student();
        $this->actingAs($this->admin());
        $start = now()->addDays(3)->startOfDay()->addHours(10);
        $slot = TeacherAvailability::create(['teacher_id' => $a['teacher']->id, 'date' => $start->toDateString(), 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'recurrence_type' => 'none', 'timezone' => config('app.timezone'), 'status' => 'active', 'price' => 50]);
        $booking = PaidBooking::create(['student_id' => $student->id, 'teacher_id' => $a['teacher']->id, 'availability_id' => $slot->id, 'status' => 'NEEDS_RESCHEDULE', 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'hold_expires_at' => now()->addDay()]);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $a['teacher']->id, 'enrollable_type' => 'paid_booking', 'enrollable_id' => $booking->id, 'status' => 'pending', 'price' => 50]);
        $payment = Payment::create(['payer_id' => $student->user_id, 'payable_type' => (new Enrollment)->getMorphClass(), 'payable_id' => $enrollment->id, 'provider' => 'sham_cash', 'provider_reference' => 'REVISION-PAID-01', 'amount' => 50, 'currency' => 'SYP', 'status' => 'succeeded']);
        $booking->update(['payment_id' => $payment->id]);
        $this->get('/admin/paid-bookings?booking_id='.$booking->id)->assertOk()->assertSee('اعتماد الموعد البديل');
        $this->post('/admin/paid-bookings/'.$booking->id.'/reschedule', ['availability_id' => $slot->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String()])->assertRedirect();
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertNotNull($booking->fresh()->session_id);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_audit_filters_and_details_are_read_only_and_hide_nested_secrets(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $entry = AuditLog::create(['user_id' => $admin->id, 'action' => 'admin.test.changed', 'entity_type' => User::class, 'entity_id' => $admin->id, 'ip' => '192.168.1.10', 'metadata_json' => ['token' => 'DO-NOT-EXPOSE', 'changes' => ['password_hash' => 'ALSO-SECRET', 'status' => ['before' => 'active', 'after' => 'suspended']]], 'created_at' => now()]);
        $this->get('/admin/audit?entry='.$entry->id)->assertOk()->assertSee('192.168.1.*')->assertSee('suspended')->assertDontSee('DO-NOT-EXPOSE')->assertDontSee('ALSO-SECRET');
        $this->get('/admin/audit?action=other.action')->assertOk()->assertDontSee('admin.test.changed</code>', false);
        $csv = $this->get('/admin/audit?export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('192.168.1.*', $csv);
        $this->assertStringNotContainsString('192.168.1.10', $csv);
        $this->delete('/admin/audit/'.$entry->id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id]);
    }

    public function test_administrator_signs_in_with_email_and_rejects_phone_only_login(): void
    {
        $admin = $this->admin();
        $admin->update(['password_hash' => Hash::make('EmailLogin123!')]);
        $this->get('/admin/login')->assertOk()->assertSee('type="email"', false)->assertSee('name="email"', false)->assertDontSee('name="phone"', false);
        $this->post('/admin/login', ['phone' => $admin->phone, 'password' => 'EmailLogin123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'EmailLogin123!'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_exceptional_refund_uses_original_payment_owner_amount_and_unique_active_request(): void
    {
        $a = $this->teacher();
        $student = $this->student();
        $this->actingAs($this->admin());
        $enrollment = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $a['teacher']->id, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $a['course']->id, 'status' => 'active', 'price' => 50]);
        $payment = Payment::create(['payer_id' => $student->user_id, 'payable_type' => $enrollment->getMorphClass(), 'payable_id' => $enrollment->id, 'provider' => 'sham_cash', 'provider_reference' => 'EXCEPTIONAL-01', 'amount' => 50, 'currency' => 'SYP', 'status' => 'succeeded']);
        PaymentLessonAllocation::create(['payment_id' => $payment->id, 'lesson_id' => $a['lesson']->id, 'lesson_title' => $a['lesson']->title, 'lesson_price_snapshot' => 50, 'allocated_amount' => 50, 'content_ids_snapshot' => [$a['content']->id]]);
        $this->get('/admin/refunds/create')->assertOk()->assertSee('EXCEPTIONAL-01');
        $data = ['payment_id' => $payment->id, 'account_id' => 'REFUND-ACCOUNT', 'reason' => 'تسوية استثنائية موثقة', 'amount' => 999, 'user_id' => $this->admin()->id, 'status' => 'COMPLETED'];
        $this->post('/admin/refunds', $data)->assertRedirect()->assertSessionHasNoErrors();
        $refund = RefundRequest::firstOrFail();
        $this->assertSame('50.00', $refund->amount);
        $this->assertSame($student->user_id, $refund->user_id);
        $this->assertSame('PENDING_REVIEW', $refund->status);
        $this->post('/admin/refunds', $data)->assertSessionHasErrors('payment_id');
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertNotNull($enrollment->fresh()->access_frozen_at);
        $this->assertDatabaseCount('refund_lesson_items', 1);
        $this->get('/admin/refunds/'.$refund->id)->assertOk()->assertSee('قرار المراجعة الإدارية');
        $export = $this->get('/admin/refunds?export=1')->assertOk()->streamedContent();
        $this->assertStringContainsString('EXCEPTIONAL-01', $export);
    }

    public function test_admin_refund_respects_new_lesson_consumption_and_rejects_unsnapshotted_legacy_prices(): void
    {
        $a = $this->teacher();
        $student = $this->student();
        $this->actingAs($this->admin());
        $e = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $a['teacher']->id, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $a['course']->id, 'status' => 'active', 'price' => 50]);
        $p = Payment::create(['payer_id' => $student->user_id, 'payable_type' => $e->getMorphClass(), 'payable_id' => $e->id, 'provider' => 'sham_cash', 'provider_reference' => 'CONSUMED-REFUND', 'amount' => 50, 'currency' => 'SYP', 'status' => 'succeeded']);
        $body = ['payment_id' => $p->id, 'account_id' => 'FIXTURE-ACCOUNT', 'reason' => 'طلب اختبار', 'amount' => 999];
        $this->post('/admin/refunds', $body)->assertSessionHasErrors('payment_id');
        $this->assertDatabaseCount('refund_requests', 0);
        $next = Lesson::create(['unit_id' => $a['unit']->id, 'title' => 'درس غير مستهلك', 'status' => 'published']);
        foreach ([[$a['lesson'], 20, [$a['content']->id]], [$next, 30, []]] as [$lesson, $amount, $ids]) {
            PaymentLessonAllocation::create(['payment_id' => $p->id, 'lesson_id' => $lesson->id, 'lesson_title' => $lesson->title, 'lesson_price_snapshot' => $amount, 'allocated_amount' => $amount, 'content_ids_snapshot' => $ids]);
        }
        LessonCompletion::create(['student_id' => $student->id, 'lesson_id' => $a['lesson']->id, 'completed_at' => now()]);
        $this->post('/admin/refunds', $body)->assertRedirect()->assertSessionHasNoErrors();
        $r = RefundRequest::firstOrFail();
        $this->assertSame('30.00', $r->amount);
        $this->assertNotNull($e->fresh()->access_frozen_at);
        $this->assertDatabaseHas('refund_lesson_items', ['refund_request_id' => $r->id, 'amount' => 30]);
        $this->get('/admin/refunds/'.$r->id)->assertOk()->assertSee('name="rejection_code"', false);
    }
}
