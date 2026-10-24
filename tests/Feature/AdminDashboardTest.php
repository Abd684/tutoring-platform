<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\RefundRequest;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\TeacherSubscription;
use App\Models\Unit;
use App\Models\User;
use App\Services\SessionRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'company_admin', 'status' => 'active', 'password_hash' => Hash::make('TestPassword123!')]);
    }

    private function fixture(): array
    {
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $studentUser->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'الرياضيات', 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'price' => 50, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'الوحدة الأولى', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'الدرس الأول', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'type' => 'quiz', 'title' => 'اختبار تدريبي', 'status' => 'published']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $course->id, 'price' => 50, 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth()]);
        $group = Group::create(['teacher_subject_id' => $course->id, 'name' => 'المجموعة الأولى', 'capacity' => 3, 'status' => 'active']);

        return compact('teacherUser', 'teacher', 'studentUser', 'student', 'subject', 'course', 'unit', 'lesson', 'content', 'enrollment', 'group');
    }

    private function protectedPages(): array
    {
        return ['/admin', '/admin/students', '/admin/students/create', '/admin/teachers', '/admin/teachers/create', '/admin/groups', '/admin/groups/create', '/admin/sessions', '/admin/sessions/create', '/admin/payments', '/admin/refunds', '/admin/subscriptions', '/admin/plans', '/admin/plans/create', '/admin/content', '/admin/content/create', '/admin/settings', '/admin/payment-settings', '/admin/audit', '/admin/search?q=Test'];
    }

    public function test_dashboard_requires_active_administrator_on_every_page(): void
    {
        foreach ($this->protectedPages() as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        foreach (['student', 'teacher'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);
            foreach ($this->protectedPages() as $path) {
                $this->get($path)->assertForbidden();
            }
        }
        $admin = $this->admin();
        $admin->update(['status' => 'suspended']);
        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_admin_login_logout_and_teacher_rejection(): void
    {
        $admin = $this->admin();
        $this->get('/admin/login')->assertOk()->assertSee('تسجيل الدخول');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'TestPassword123!'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
        $teacher = User::factory()->create(['role' => 'teacher', 'password_hash' => Hash::make('TestPassword123!')]);
        $this->post('/admin/login', ['email' => $teacher->email, 'password' => 'TestPassword123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_all_list_and_create_screens_render_empty_states_and_real_forms(): void
    {
        $this->actingAs($this->admin());
        foreach ($this->protectedPages() as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/admin')->assertSee('لا توجد دفعات معلقة')->assertDontSee('1,284');
        $this->get('/admin/students/create')->assertSee('name="_token"', false)->assertSee('password_confirmation');
    }

    public function test_detail_edit_and_quiz_screens_render_real_relationships(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $session = Session::create(['teacher_id' => $f['teacher']->id, 'type' => 'GROUP', 'group_id' => $f['group']->id, 'capacity' => 3, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled']);
        $plan = SubscriptionPlan::create(['name' => 'خطة تجريبية', 'price' => 10, 'billing_cycle' => 'monthly', 'status' => 'active']);
        foreach (['/admin/students/'.$f['studentUser']->id, '/admin/students/'.$f['studentUser']->id.'/edit', '/admin/teachers/'.$f['teacherUser']->id, '/admin/teachers/'.$f['teacherUser']->id.'/edit', '/admin/groups/'.$f['group']->id, '/admin/groups/'.$f['group']->id.'/edit', '/admin/sessions/'.$session->id, '/admin/plans/'.$plan->id.'/edit', '/admin/content/'.$f['content']->id.'/edit', '/admin/content/'.$f['content']->id.'/quiz'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_create_student_preserves_role_and_uses_user_id_for_details(): void
    {
        $this->actingAs($this->admin());
        $data = ['name' => 'طالب جديد', 'email' => 'new-student@example.invalid', 'phone' => 'qa001', 'password' => 'TestPassword123!', 'password_confirmation' => 'TestPassword123!', 'status' => 'active', 'role' => 'company_admin'];
        $this->post('/admin/students', $data)->assertRedirect();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('student', $user->role);
        $this->assertNotNull($user->student);
        $this->assertTrue(Hash::check($data['password'], $user->password_hash));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.account.created', 'entity_id' => $user->id]);
        $this->get('/admin/teachers/'.$user->id)->assertNotFound();
        $this->post('/admin/students', $data)->assertSessionHasErrors(['email', 'phone']);
    }

    public function test_teacher_creation_and_profile_edit_do_not_accept_role_or_password_changes(): void
    {
        $this->actingAs($this->admin());
        $f = $this->fixture();
        $data = ['name' => 'مدرس جديد', 'email' => 'new-teacher@example.invalid', 'phone' => 'qa002', 'password' => 'TestPassword123!', 'password_confirmation' => 'TestPassword123!', 'status' => 'active', 'teacher_status' => 'active', 'subject_ids' => [$f['subject']->id], 'subject_price' => 40];
        $this->post('/admin/teachers', $data)->assertRedirect();
        $u = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame('active', $u->teacher->status);
        $this->assertDatabaseHas('teacher_subjects', ['teacher_id' => $u->teacher->id, 'subject_id' => $f['subject']->id]);
        unset($data['password'],$data['password_confirmation']);
        $data['name'] = 'اسم معدل';
        $data['role'] = 'company_admin';
        $this->put('/admin/teachers/'.$u->id, $data)->assertRedirect();
        $this->assertSame('teacher', $u->fresh()->role);
        $this->assertSame('اسم معدل', $u->fresh()->name);
        $this->put('/admin/teachers/'.$u->id, [...$data, 'password' => 'ChangedPassword123!'])->assertSessionHasErrors('password');
    }

    public function test_explicit_admin_grant_creates_matching_enrollment_and_membership_atomically(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $data = ['name' => 'طالب منحة', 'email' => 'grant@example.invalid', 'phone' => 'qa003', 'password' => 'TestPassword123!', 'password_confirmation' => 'TestPassword123!', 'status' => 'active', 'grant_access' => 1, 'course_id' => $f['course']->id, 'group_id' => $f['group']->id, 'grant_reason' => 'تجربة إدارية'];
        $this->post('/admin/students', $data)->assertRedirect();
        $user = User::where('email', $data['email'])->firstOrFail();
        $e = $user->student->enrollments()->firstOrFail();
        $this->assertSame('active', $e->status);
        $this->assertSame('0.00', $e->price);
        $this->assertDatabaseHas('group_enrollments', ['group_id' => $f['group']->id, 'enrollment_id' => $e->id, 'status' => 'active']);
        $this->assertDatabaseCount('payments', 0);
        $this->post('/admin/students', [...$data, 'email' => 'bad-grant@example.invalid', 'phone' => 'qa004', 'course_id' => 99999])->assertSessionHasErrors('course_id');
        $this->assertDatabaseMissing('users', ['email' => 'bad-grant@example.invalid']);
    }

    public function test_group_membership_uses_full_subject_rules_and_capacity(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $path = '/admin/groups/'.$f['group']->id.'/members';
        $unitE = Enrollment::create(['student_id' => $f['student']->id, 'teacher_id' => $f['teacher']->id, 'enrollable_type' => 'unit', 'enrollable_id' => $f['unit']->id, 'status' => 'active']);
        $this->post($path, ['enrollment_id' => $unitE->id])->assertSessionHasErrors('enrollment_id');
        $this->post($path, ['enrollment_id' => $f['enrollment']->id])->assertRedirect();
        $this->assertDatabaseCount('group_enrollments', 1);
        $this->post($path, ['enrollment_id' => $f['enrollment']->id])->assertSessionHasErrors('enrollment_id');
        $m = GroupEnrollment::firstOrFail();
        $other = Group::create(['teacher_subject_id' => $f['course']->id, 'name' => 'أخرى', 'capacity' => 1, 'status' => 'active']);
        $this->delete('/admin/groups/'.$other->id.'/members/'.$m->id)->assertNotFound();
        $this->delete($path.'/'.$m->id)->assertRedirect();
        $this->assertSame('removed', $m->fresh()->status);
    }

    public function test_admin_scheduling_checks_teacher_ownership_type_and_overlap(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $state = ['teacher_id' => $f['teacher']->id, 'type' => 'INDIVIDUAL', 'enrollment_id' => $f['enrollment']->id, 'starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->addHour()->toIso8601String()];
        $this->post('/admin/sessions', $state)->assertRedirect();
        $this->assertDatabaseHas('sessions', ['type' => 'INDIVIDUAL', 'capacity' => 1, 'enrollment_id' => $f['enrollment']->id]);
        $this->post('/admin/sessions', $state)->assertSessionHasErrors('starts_at');
        $this->post('/admin/sessions', [...$state, 'group_id' => $f['group']->id])->assertSessionHasErrors('enrollment_id');
        $this->post('/admin/sessions/'.$f['enrollment']->sessions()->firstOrFail()->id.'/cancel')->assertRedirect();
        $this->assertDatabaseHas('sessions', ['status' => 'cancelled', 'enrollment_id' => $f['enrollment']->id]);
    }

    public function test_admin_can_link_matching_availability_but_not_another_teachers_availability(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $start = now()->addDays(3)->startOfDay()->addHours(10);
        $slot = TeacherAvailability::create(['teacher_id' => $f['teacher']->id, 'date' => $start->toDateString(), 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'recurrence_type' => 'none', 'timezone' => config('app.timezone'), 'status' => 'active']);
        $state = ['teacher_id' => $f['teacher']->id, 'type' => 'INDIVIDUAL', 'enrollment_id' => $f['enrollment']->id, 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'availability_id' => $slot->id];
        $other = $this->fixture();
        $slot->update(['teacher_id' => $other['teacher']->id]);
        $this->post('/admin/sessions', $state)->assertSessionHasErrors('availability_id');
        $this->assertDatabaseCount('sessions', 0);
        $slot->update(['teacher_id' => $f['teacher']->id]);
        $this->post('/admin/sessions', $state)->assertRedirect();
        $this->assertDatabaseHas('sessions', ['availability_id' => $slot->id, 'teacher_id' => $f['teacher']->id]);
    }

    public function test_administrator_schedule_flag_cannot_be_used_by_other_roles(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['teacherUser']);
        $this->expectException(AuthorizationException::class);
        app(SessionRules::class)->validate([], true);
    }

    public function test_payment_web_review_runs_existing_payment_state_machine_and_audit(): void
    {
        $f = $this->fixture();
        $f['enrollment']->update(['status' => 'pending']);
        $payment = Payment::create(['payer_id' => $f['studentUser']->id, 'payable_type' => $f['enrollment']->getMorphClass(), 'payable_id' => $f['enrollment']->id, 'amount' => 50, 'currency' => 'SYP', 'provider' => 'sham_cash', 'provider_reference' => 'WEB-REVIEW-1', 'status' => 'pending', 'service_snapshot' => ['type' => 'teacher_subject', 'id' => $f['course']->id, 'title' => 'الرياضيات']]);
        $this->actingAs($this->admin());
        $this->get('/admin/payments/'.$payment->id)->assertOk();
        $this->post('/admin/payments/'.$payment->id.'/review', ['decision' => 'confirm'])->assertRedirect();
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('active', $f['enrollment']->fresh()->status);
        $this->assertNotNull($payment->fresh()->reviewed_by);
        $this->post('/admin/payments/'.$payment->id.'/review', ['decision' => 'confirm'])->assertSessionHasErrors('operation');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.confirm', 'entity_id' => $payment->id]);
    }

    public function test_manual_refund_requires_approval_then_distinct_transfer_and_cancels_access(): void
    {
        $f = $this->fixture();
        $payment = Payment::create(['payer_id' => $f['studentUser']->id, 'payable_type' => $f['enrollment']->getMorphClass(), 'payable_id' => $f['enrollment']->id, 'amount' => 50, 'currency' => 'SYP', 'provider' => 'sham_cash', 'provider_reference' => 'INCOMING', 'status' => 'succeeded']);
        $refund = RefundRequest::create(['payment_id' => $payment->id, 'active_payment_id' => $payment->id, 'user_id' => $f['studentUser']->id, 'amount' => 50, 'currency' => 'SYP', 'account_id' => 'QA-ACCOUNT', 'reason_code' => 'exceptional', 'reason' => 'سبب تجريبي', 'status' => 'PENDING_REVIEW']);
        $this->actingAs($this->admin());
        $this->get('/admin/refunds/'.$refund->id)->assertOk();
        $this->post('/admin/refunds/'.$refund->id.'/complete', ['transfer_reference' => 'OUTGOING'])->assertSessionHasErrors('operation');
        $this->post('/admin/refunds/'.$refund->id.'/review', ['decision' => 'approve', 'review_note' => 'تم التحقق'])->assertRedirect();
        $this->assertSame('APPROVED', $refund->fresh()->status);
        $this->post('/admin/refunds/'.$refund->id.'/complete', ['transfer_reference' => 'OUTGOING'])->assertRedirect();
        $this->assertSame('COMPLETED', $refund->fresh()->status);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('cancelled', $f['enrollment']->fresh()->status);
    }

    public function test_plan_assignment_preserves_history_and_prevents_overlap(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $this->post('/admin/plans', ['name' => 'خطة', 'price' => 10, 'billing_cycle' => 'monthly', 'commission_rate' => 5, 'status' => 'active', 'max_students' => 0])->assertRedirect();
        $plan = SubscriptionPlan::firstOrFail();
        $data = ['teacher_id' => $f['teacher']->id, 'plan_id' => $plan->id, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addMonth()->toIso8601String()];
        $this->post('/admin/subscriptions', $data)->assertRedirect();
        $this->post('/admin/subscriptions', $data)->assertSessionHasErrors('starts_at');
        $subscription = TeacherSubscription::firstOrFail();
        $this->post('/admin/subscriptions/'.$subscription->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $subscription->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_private_file_upload_archiving_and_scheduled_publication(): void
    {
        Storage::fake('local');
        config(['filesystems.media_disk' => 'local']);
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $data = ['lesson_id' => $f['lesson']->id, 'title' => 'ملف مقرر', 'type' => 'pdf', 'order_no' => 2, 'status' => 'scheduled', 'scheduled_publish_at' => now()->addHours(2)->toIso8601String(), 'learning_objectives' => "هدف أول\nهدف ثانٍ", 'file' => UploadedFile::fake()->create('lesson.pdf', 20, 'application/pdf')];
        $this->post('/admin/content', $data)->assertRedirect();
        $content = Content::where('title', 'ملف مقرر')->firstOrFail();
        $this->assertSame('draft', $content->status);
        $this->assertCount(2, $content->learning_objectives);
        $asset = $content->assets()->firstOrFail();
        Storage::disk('local')->assertExists($asset->storage_key);
        $this->get('/admin/content-assets/'.$asset->id)->assertOk();
        $this->travel(3)->hours();
        $this->artisan('content:publish-scheduled')->assertSuccessful();
        $this->assertSame('published', $content->fresh()->status);
        $this->post('/admin/content/'.$content->id.'/archive')->assertRedirect();
        $this->assertSame('archived', $content->fresh()->status);
        Storage::disk('local')->assertExists($asset->storage_key);
        $this->actingAs($f['studentUser'])->get('/admin/content-assets/'.$asset->id)->assertForbidden();
    }

    public function test_content_rejects_wrong_file_type_without_leaving_a_record(): void
    {
        Storage::fake('local');
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $count = Content::count();
        $this->post('/admin/content', ['lesson_id' => $f['lesson']->id, 'title' => 'ملف خاطئ', 'type' => 'pdf', 'order_no' => 1, 'status' => 'draft', 'file' => UploadedFile::fake()->image('image.png')])->assertSessionHasErrors('file');
        $this->assertSame($count, Content::count());
    }

    public function test_quiz_editor_saves_questions_and_never_exposes_reference_answers_to_student(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->admin());
        $this->put('/admin/content/'.$f['content']->id.'/quiz', ['attempts_allowed' => 2, 'duration_minutes' => 30, 'status' => 'published', 'questions' => [['type' => 'mcq', 'body' => 'كم تساوي2+2؟', 'points' => 1, 'options' => "3\n4", 'correct_answer' => '1']]])->assertRedirect();
        $quiz = $f['content']->quizzes()->firstOrFail();
        $this->assertSame('1', $quiz->questions()->first()->rubric_json['correct_answer']);
        Sanctum::actingAs($f['studentUser']);
        $r = $this->getJson('/api/v1/quizzes/'.$quiz->id.'/data')->assertOk()->assertJsonPath('data.quiz.questions.0.rubric_json.options', ['3', '4']);
        $this->assertStringNotContainsString('correct_answer', $r->getContent());
    }

    public function test_quiz_with_attempts_cannot_have_its_questions_replaced(): void
    {
        $f = $this->fixture();
        $q = Quiz::create(['content_id' => $f['content']->id, 'status' => 'published', 'attempts_allowed' => 2]);
        $question = Question::create(['quiz_id' => $q->id, 'body' => 'سؤال محفوظ', 'type' => 'essay', 'points' => 3]);
        QuizAttempt::create(['quiz_id' => $q->id, 'student_id' => $f['student']->id, 'status' => 'in_progress', 'started_at' => now()]);
        $this->actingAs($this->admin());
        $this->put('/admin/content/'.$f['content']->id.'/quiz', ['attempts_allowed' => 2, 'status' => 'published', 'questions' => [['id' => $question->id, 'type' => 'essay', 'body' => 'تعديل غير مسموح', 'points' => 10]]])->assertSessionHasErrors('questions');
        $this->assertSame('سؤال محفوظ', $question->fresh()->body);
    }

    public function test_settings_are_persistent_and_search_and_csv_use_real_data(): void
    {
        $f = $this->fixture();
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->put('/admin/settings', ['platform_name' => 'لقاء التعليمي', 'support_email' => 'support@example.invalid', 'timezone' => 'Asia/Damascus', 'theme' => 'dark', 'density' => 'compact'])->assertRedirect();
        $this->get('/admin')->assertOk()->assertSee('لقاء التعليمي')->assertSee('data-theme="dark"', false);
        $this->get('/admin/search?q='.urlencode($f['studentUser']->name))->assertOk()->assertSee($f['studentUser']->name);
        $f['studentUser']->update(['name' => '=UNSAFE()']);
        $r = $this->get('/admin/students?export=1')->assertOk();
        $this->assertStringContainsString("'=UNSAFE()",$r->streamedContent());
        $this->assertDatabaseHas('platform_settings',['key' => 'dashboard']);
    }
}
