<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherFrontendWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): array
    {
        $u = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $t = Teacher::create(['user_id' => $u->id, 'status' => 'active']);
        $c = TeacherSubject::create(['teacher_id' => $t->id, 'subject_id' => Subject::create(['name' => 'Subject '.$u->id, 'status' => 'active'])->id, 'status' => 'active']);
        $g = Group::create(['teacher_subject_id' => $c->id, 'name' => 'Group '.$u->id, 'capacity' => 1, 'status' => 'active']);

        return compact('u', 't', 'c', 'g');
    }

    private function student(): Student
    {
        return Student::create(['user_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id, 'status' => 'active']);
    }

    private function requestFor(Group $g, Student $s): int
    {
        Sanctum::actingAs($s->user);

        return $this->postJson('/api/v1/student/groups/'.$g->id.'/join-request', ['message' => 'Please review'])->assertCreated()->assertJsonPath('data.requires_teacher_approval', true)->assertJsonPath('data.seat_reserved', false)->assertJsonPath('data.join_request.status', 'pending')->json('data.join_request.id');
    }

    public function test_student_request_is_pending_duplicate_is_idempotent_and_teacher_approves_without_online_subscription(): void
    {
        $f = $this->teacher();
        $s = $this->student();
        $id = $this->requestFor($f['g'], $s);
        $this->assertDatabaseCount('group_students', 0);
        $this->postJson('/api/v1/student/groups/'.$f['g']->id.'/join')->assertOk()->assertJsonPath('data.join_request.id', $id);
        $this->assertDatabaseCount('group_join_requests', 1);
        $this->getJson('/api/v1/student/groups/'.$f['g']->id.'/join-requests')->assertOk()->assertJsonPath('meta.total', 1);
        Sanctum::actingAs($f['u']);
        $this->getJson('/api/v1/teacher/groups/'.$f['g']->id.'/join-requests')->assertOk()->assertJsonPath('data.0.student.user.name', $s->user->name)->assertJsonMissingPath('data.0.student.user.password_hash');
        $path = '/api/v1/teacher/groups/'.$f['g']->id.'/join-requests/'.$id;
        $this->postJson($path.'/approve')->assertOk()->assertJsonPath('data.join_request.status', 'approved')->assertJsonPath('data.membership.status', 'active');
        $this->postJson($path.'/approve')->assertOk();
        $this->postJson($path.'/reject', ['review_note' => 'Different decision'])->assertConflict();
        $this->assertDatabaseCount('group_students', 1);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->getJson('/api/v1/teacher/groups/'.$f['g']->id.'/students?status=active')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $s->user_id, 'type' => 'group_join_approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group_join_request.approved', 'entity_id' => $id]);
    }

    public function test_rejection_preserves_history_and_student_may_submit_a_new_request(): void
    {
        $f = $this->teacher();
        $s = $this->student();
        $id = $this->requestFor($f['g'], $s);
        Sanctum::actingAs($f['u']);
        $path = '/api/v1/teacher/groups/'.$f['g']->id.'/join-requests/'.$id.'/reject';
        $this->postJson($path)->assertUnprocessable();
        $this->postJson($path, ['review_note' => '   '])->assertUnprocessable();
        $this->postJson($path, ['review_note' => 'No suitable level'])->assertOk()->assertJsonPath('data.membership', null);
        $next = $this->requestFor($f['g'], $s);
        $this->assertNotSame($id, $next);
        $this->assertDatabaseCount('group_join_requests', 2);
        $this->assertDatabaseCount('group_students', 0);
    }

    public function test_pending_requests_do_not_reserve_seats_and_only_one_approval_claims_last_seat(): void
    {
        $f = $this->teacher();
        $a = $this->student();
        $b = $this->student();
        $one = $this->requestFor($f['g'], $a);
        $two = $this->requestFor($f['g'], $b);
        Sanctum::actingAs($f['u']);
        $p = '/api/v1/teacher/groups/'.$f['g']->id.'/join-requests/';
        $this->postJson($p.$one.'/approve')->assertOk();
        $this->postJson($p.$two.'/approve')->assertConflict();
        $this->assertDatabaseHas('group_join_requests', ['id' => $two, 'status' => 'pending']);
        $this->assertDatabaseCount('group_students', 1);
        $this->postJson($p.$two.'/reject', ['review_note' => 'Group is full'])->assertOk();
    }

    public function test_teacher_ownership_and_student_privacy_and_active_accounts_are_enforced(): void
    {
        $a = $this->teacher();
        $b = $this->teacher();
        $s = $this->student();
        $id = $this->requestFor($a['g'], $s);
        $path = '/api/v1/teacher/groups/'.$a['g']->id.'/join-requests';
        Sanctum::actingAs($b['u']);
        $this->getJson($path)->assertNotFound();
        $this->postJson($path.'/'.$id.'/approve')->assertNotFound();
        $this->postJson('/api/v1/teacher/groups/'.$b['g']->id.'/join-requests/'.$id.'/approve')->assertNotFound();
        $other = $this->student();
        Sanctum::actingAs($other->user);
        $this->getJson('/api/v1/student/groups/'.$a['g']->id.'/join-requests')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($path)->assertForbidden();
        $this->postJson('/api/v1/student/groups/'.$a['g']->id.'/join-request', ['student_id' => $s->id])->assertUnprocessable();
        Sanctum::actingAs($a['u']);
        $s->update(['status' => 'suspended']);
        $this->postJson($path.'/'.$id.'/approve')->assertForbidden();
        $this->assertDatabaseCount('group_students', 0);
        $this->assertDatabaseHas('group_join_requests', ['id' => $id, 'status' => 'pending']);
    }

    public function test_direct_teacher_add_also_finishes_pending_request_and_repeated_add_does_not_duplicate(): void
    {
        $f = $this->teacher();
        $s = $this->student();
        $id = $this->requestFor($f['g'], $s);
        Sanctum::actingAs($f['u']);
        $p = '/api/v1/teacher/groups/'.$f['g']->id.'/students';
        $this->postJson($p, ['student_id' => $s->id])->assertCreated();
        $this->assertDatabaseHas('group_join_requests', ['id' => $id, 'status' => 'approved']);
        $this->postJson($p, ['student_id' => $s->id])->assertConflict();
        $this->assertDatabaseCount('group_students', 1);
    }

    public function test_approval_checks_session_conflicts_and_rolls_back_all_side_effects_on_failure(): void
    {
        $a = $this->teacher();
        $b = $this->teacher();
        $s = $this->student();
        $id = $this->requestFor($a['g'], $s);
        GroupStudent::create(['group_id' => $b['g']->id, 'student_id' => $s->id, 'status' => 'active', 'joined_at' => now()]);
        foreach ([$a, $b] as $f) {
            Session::create(['teacher_id' => $f['t']->id, 'type' => 'group', 'type_id' => $f['g']->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'capacity' => 1, 'status' => 'scheduled']);
        }
        Sanctum::actingAs($a['u']);
        $this->postJson('/api/v1/teacher/groups/'.$a['g']->id.'/join-requests/'.$id.'/approve')->assertConflict();
        $this->assertDatabaseHas('group_join_requests', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseMissing('group_students', ['group_id' => $a['g']->id, 'student_id' => $s->id]);
    }

    public function test_audit_failure_rolls_back_approval_and_membership(): void
    {
        $f = $this->teacher();
        $s = $this->student();
        $id = $this->requestFor($f['g'], $s);
        Sanctum::actingAs($f['u']);
        AuditLog::creating(fn () => throw new \RuntimeException('audit failure'));
        try {
            $this->postJson('/api/v1/teacher/groups/'.$f['g']->id.'/join-requests/'.$id.'/approve')->assertStatus(500);
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertDatabaseCount('group_students', 0);
        $this->assertDatabaseHas('group_join_requests', ['id' => $id, 'status' => 'pending']);
        $this->assertDatabaseMissing('notifications', ['type' => 'group_join_approved']);
    }

    public function test_today_uses_local_day_boundaries_and_excludes_other_teachers(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01T12:00:00Z'));
        $f = $this->teacher();
        $other = $this->teacher();
        $ids = [];
        foreach ([['2026-10-31T20:30:00Z', '2026-10-31T21:00:00Z'], ['2026-10-31T20:30:00Z', '2026-10-31T21:30:00Z'], ['2026-11-01T20:30:00Z', '2026-11-01T21:30:00Z'], ['2026-11-01T21:00:00Z', '2026-11-01T22:00:00Z']] as [$start,$end]) {
            $ids[] = Session::create(['teacher_id' => $f['t']->id, 'type' => 'group', 'type_id' => $f['g']->id, 'starts_at' => $start, 'ends_at' => $end, 'capacity' => 1, 'status' => 'scheduled'])->id;
        }
        Session::create(['teacher_id' => $other['t']->id, 'type' => 'group', 'type_id' => $other['g']->id, 'starts_at' => '2026-11-01T10:00:00Z', 'ends_at' => '2026-11-01T11:00:00Z', 'capacity' => 1, 'status' => 'scheduled']);
        Sanctum::actingAs($f['u']);
        $r = $this->getJson('/api/v1/teacher/sessions?scope=today&timezone=Asia/Damascus')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$ids[1], $ids[2]], array_column($r->json('data'), 'id'));
        $this->getJson('/api/v1/teacher/sessions?date=2026-11-01&timezone=UTC')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/teacher/sessions?scope=today&date=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/v1/teacher/sessions?timezone=Invalid/Zone')->assertUnprocessable();
    }

    public function test_group_schedule_reuses_sessions_creation_listing_update_and_conflict_rules(): void
    {
        $f = $this->teacher();
        Sanctum::actingAs($f['u']);
        $p = '/api/v1/teacher/sessions';
        $d = ['type' => 'group', 'type_id' => $f['g']->id, 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String()];
        $id = $this->postJson($p, $d)->assertCreated()->json('data.id');
        $this->postJson($p, $d)->assertConflict();
        $this->getJson($p.'?type=group&type_id='.$f['g']->id)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->patchJson($p.'/'.$id, ['starts_at' => now()->addDays(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->addHour()->toIso8601String()])->assertOk();
        $b = $this->teacher();
        $this->getJson($p.'?type=group&type_id='.$b['g']->id)->assertNotFound();
        $this->getJson($p.'?type_id='.$f['g']->id)->assertUnprocessable();
    }
}
