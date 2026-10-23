<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Governortate;
use App\Models\Group;
use App\Models\Region;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\TeacherSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherFrontendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(string $status = 'active'): array
    {
        $u = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $t = Teacher::create(['user_id' => $u->id, 'status' => $status]);

        return [$u, $t];
    }

    private function region(): Region
    {
        $p = Governortate::create(['name' => 'QA province']);

        return Region::create(['name' => 'QA region', 'governortate_id' => $p->id]);
    }

    private function plan(array $extra = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_replace(['name' => 'Monthly', 'price' => 10, 'billing_cycle' => 'monthly', 'max_ai_usage' => 5, 'max_students' => 2, 'max_group_size' => 3, 'status' => 'active'], $extra));
    }

    public function test_registration_keeps_legacy_message_and_does_not_issue_tokens(): void
    {
        $data = ['name' => 'QA Teacher', 'email' => 'qa@example.invalid', 'phone' => 'qa123', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'region_id' => $this->region()->id];
        $this->postJson('/api/v1/teacher/register', $data)->assertCreated()->assertExactJson(['message' => 'Teacher registered successfully']);
        $this->assertDatabaseHas('teachers', ['status' => 'pending_review']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/v1/teacher/register', $data)->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone']);
        $this->postJson('/api/v1/teacher/login', ['email' => $data['email'], 'password' => $data['password']])->assertOk()->assertJsonPath('user.teacher_status', 'pending_review');
    }

    public function test_me_preserves_top_level_user_and_nested_teacher_without_secrets(): void
    {
        [$u, $t] = $this->teacher();
        Sanctum::actingAs($u);
        $r = $this->getJson('/api/v1/teacher/me')->assertOk()->assertJsonPath('id', $u->id)->assertJsonPath('teacher.id', $t->id)->assertJsonMissingPath('data')->assertJsonMissingPath('password_hash');
        $this->assertArrayNotHasKey('password_hash', $r->json());
    }

    public function test_profile_updates_account_and_teacher_atomically_and_preserves_existing_fields(): void
    {
        [$u, $t] = $this->teacher();
        $region = $this->region();
        Sanctum::actingAs($u);
        $this->patchJson('/api/v1/teacher/profile', ['name' => 'Updated', 'email' => 'changed@example.invalid', 'phone' => 'new123', 'region_id' => $region->id, 'bio' => 'Bio', 'specialization' => 'Math', 'experience_years' => 9, 'status' => 'suspended', 'role' => 'company_admin', 'user_id' => 999])
            ->assertOk()->assertJsonPath('data.id', $t->id)->assertJsonPath('data.specialization', 'Math')->assertJsonPath('data.user.name', 'Updated')->assertJsonPath('data.user.role', 'teacher')->assertJsonPath('data.status', 'active')->assertJsonMissingPath('data.user.password_hash');
        $this->assertSame('changed@example.invalid', $u->fresh()->email);
        $this->getJson('/api/v1/teacher/me')->assertOk()->assertJsonPath('name', 'Updated')->assertJsonPath('teacher.bio', 'Bio');
        $this->patchJson('/api/v1/teacher/profile', ['bio' => null])->assertOk()->assertJsonPath('data.bio', null)->assertJsonPath('data.user.name', 'Updated');
    }

    public function test_invalid_profile_does_not_partially_save_or_accept_duplicate_identity(): void
    {
        [$u, $t] = $this->teacher();
        [$other] = $this->teacher();
        Sanctum::actingAs($u);
        $this->patchJson('/api/v1/teacher/profile', ['name' => 'Should not save', 'email' => $other->email, 'bio' => 'Wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotSame('Should not save', $u->fresh()->name);
        $this->assertNull($t->fresh()->bio);
        $this->patchJson('/api/v1/teacher/profile', ['phone' => $other->phone])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->patchJson('/api/v1/teacher/profile', ['region_id' => 9999])->assertUnprocessable();
        $this->patchJson('/api/v1/teacher/profile', ['email' => $u->email, 'phone' => $u->phone])->assertOk();
    }

    public function test_teacher_can_add_catalog_subject_only_for_self_with_validation(): void
    {
        [$u, $t] = $this->teacher();
        $s = Subject::create(['name' => 'Math', 'status' => 'active']);
        $hidden = Subject::create(['name' => 'Hidden', 'status' => 'inactive']);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/teacher/subject-catalog')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $s->id);
        $this->postJson('/api/v1/teacher/subjects', ['subject_id' => $s->id, 'price' => 25, 'group_enabled' => true, 'max_group_size' => 4])->assertCreated()->assertJsonPath('data.teacher_id', $t->id)->assertJsonPath('data.subject.id', $s->id)->assertJsonPath('data.group_enabled', true);
        $this->postJson('/api/v1/teacher/subjects', ['subject_id' => $s->id, 'price' => 30])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->postJson('/api/v1/teacher/subjects', ['subject_id' => $hidden->id, 'price' => 20])->assertUnprocessable();
        $this->postJson('/api/v1/teacher/subjects', ['subject_id' => $s->id, 'price' => 20, 'teacher_id' => 99])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->postJson('/api/v1/teacher/subjects', ['subject_id' => $s->id, 'price' => -1])->assertUnprocessable();
        $this->assertDatabaseCount('teacher_subjects', 1);
        $this->assertDatabaseCount('subjects', 2);
    }

    public function test_disabled_groups_clear_size_and_existing_subject_browse_still_works(): void
    {
        [$u, $t] = $this->teacher();
        $s = Subject::create(['name' => 'Math', 'status' => 'active']);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/teacher/subjects', ['subject_id' => $s->id, 'price' => 0, 'max_group_size' => 6])->assertCreated()->assertJsonPath('data.max_group_size', null);
        $this->getJson('/api/v1/teachers/'.$t->id.'/subjects')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_current_and_history_are_scoped_and_empty_is_explicit_null(): void
    {
        [$u, $t] = $this->teacher();
        [, $other] = $this->teacher();
        $p = $this->plan();
        Sanctum::actingAs($u);
        TeacherSubscription::create(['teacher_id' => $other->id, 'plan_id' => $p->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'status' => 'active']);
        $this->getJson('/api/v1/teacher/subscription')->assertOk()->assertExactJson(['status' => true, 'data' => null]);
        $this->getJson('/api/v1/teacher/subscriptions?teacher_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/teacher/subscription-plans/'.$p->id.'/subscribe', ['auto_renew' => true])->assertCreated();
        $this->getJson('/api/v1/teacher/subscription')->assertOk()->assertJsonPath('data.plan_id', $p->id)->assertJsonPath('data.plan.name', 'Monthly')->assertJsonPath('data.effective_status', 'active')->assertJsonPath('data.auto_renew', true);
    }

    public function test_history_exposes_effective_expiry_without_read_side_mutation_and_handles_future(): void
    {
        [$u, $t] = $this->teacher();
        $p = $this->plan();
        Sanctum::actingAs($u);
        $expired = TeacherSubscription::create(['teacher_id' => $t->id, 'plan_id' => $p->id, 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth(), 'status' => 'active']);
        TeacherSubscription::create(['teacher_id' => $t->id, 'plan_id' => $p->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addMonth(), 'status' => 'active']);
        $this->getJson('/api/v1/teacher/subscription')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/v1/teacher/subscriptions?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.effective_status', 'expired');
        $this->assertSame('active', $expired->fresh()->status);
        $this->getJson('/api/v1/teacher/subscriptions')->assertOk()->assertJsonPath('data.0.effective_status', 'scheduled');
        $this->getJson('/api/v1/teacher/subscriptions?per_page=101')->assertUnprocessable();
    }

    public function test_plans_and_subscribe_preserve_status_codes_and_history(): void
    {
        [$u, $t] = $this->teacher();
        $p = $this->plan();
        $p2 = $this->plan(['name' => 'Year', 'billing_cycle' => 'yearly']);
        $hidden = $this->plan(['status' => 'inactive']);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/teacher/subscription-plans?billing_cycle=monthly')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/teacher/subscription-plans/'.$p->id.'/subscribe', ['auto_renew' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/teacher/subscription-plans/'.$hidden->id.'/subscribe')->assertUnprocessable();
        $this->postJson('/api/v1/teacher/subscription-plans/'.$p->id.'/subscribe')->assertCreated()->assertJsonPath('data.auto_renew', false);
        $this->postJson('/api/v1/teacher/subscription-plans/'.$p->id.'/subscribe')->assertConflict();
        $this->postJson('/api/v1/teacher/subscription-plans/'.$p2->id.'/subscribe')->assertOk()->assertJsonPath('data.plan_id', $p2->id);
        $this->getJson('/api/v1/teacher/subscriptions')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.status', 'cancelled');
    }

    public function test_usage_reports_real_own_counts_and_does_not_invent_quotas(): void
    {
        [$u, $t] = $this->teacher();
        [, $other] = $this->teacher();
        $s = Subject::create(['name' => 'Math', 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $t->id, 'subject_id' => $s->id, 'status' => 'active']);
        $foreign = TeacherSubject::create(['teacher_id' => $other->id, 'subject_id' => $s->id, 'status' => 'active']);
        foreach ([$course, $foreign] as $c) {
            Group::create(['teacher_subject_id' => $c->id, 'name' => 'Group', 'status' => 'active', 'capacity' => 3]);
        }
        Session::create(['teacher_id' => $t->id, 'type' => 'INDIVIDUAL', 'capacity' => 1, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled']);
        $studentUser = User::factory()->create(['role' => 'student']);
        $student = Student::create(['user_id' => $studentUser->id, 'status' => 'active']);
        for ($i = 0; $i < 2; $i++) {
            Enrollment::create(['teacher_id' => $t->id, 'student_id' => $student->id, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $course->id, 'status' => 'active']);
        }
        $p = $this->plan();
        TeacherSubscription::create(['teacher_id' => $t->id, 'plan_id' => $p->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'status' => 'active']);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/teacher/usage?teacher_id='.$other->id)->assertOk()->assertJsonPath('data.groups.used', 1)->assertJsonPath('data.sessions.used', 1)->assertJsonPath('data.students.used', 1)->assertJsonPath('data.students.remaining', 1)
            ->assertJsonPath('data.groups.limit', null)->assertJsonPath('data.sessions.remaining', null)->assertJsonPath('data.ai_reports.used', null)->assertJsonPath('data.ai_reports.supported', false)->assertJsonPath('data.ai_reports.limit', 5)->assertJsonPath('data.quota_enforcement', false);
    }

    public function test_usage_without_subscription_and_zero_limits_are_not_unlimited(): void
    {
        [$u, $t] = $this->teacher();
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/teacher/usage')->assertOk()->assertJsonPath('data.subscription_id', null)->assertJsonPath('data.plan_limits', null)->assertJsonPath('data.groups.used', 0);
        $p = $this->plan(['max_students' => 0, 'max_ai_usage' => 0]);
        TeacherSubscription::create(['teacher_id' => $t->id, 'plan_id' => $p->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'status' => 'active']);
        $this->getJson('/api/v1/teacher/usage')->assertOk()->assertJsonPath('data.students.limit', 0)->assertJsonPath('data.students.remaining', 0)->assertJsonPath('data.ai_reports.limit', 0)->assertJsonPath('data.ai_reports.remaining', null);
    }

    public function test_new_routes_deny_anonymous_other_roles_and_inactive_teachers(): void
    {
        $paths = ['/teacher/subscription', '/teacher/subscriptions', '/teacher/usage', '/teacher/subject-catalog'];
        foreach ($paths as $path) {
            $this->getJson('/api/v1'.$path)->assertUnauthorized();
        }
        $u = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($u);
        foreach ($paths as $path) {
            $this->getJson('/api/v1'.$path)->assertForbidden();
        }
        [$pending] = $this->teacher('pending_review');
        Sanctum::actingAs($pending);
        foreach ($paths as $path) {
            $this->getJson('/api/v1'.$path)->assertForbidden();
        }
        $this->patchJson('/api/v1/teacher/profile', ['name' => 'No'])->assertForbidden();
    }
}
