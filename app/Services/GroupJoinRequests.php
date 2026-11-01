<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Models\GroupStudent;
use App\Models\Notification;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class GroupJoinRequests
{
    private function group(Group $group, int $teacherId): Group
    {
        Teacher::whereKey($teacherId)->lockForUpdate()->firstOrFail();

        return Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
    }

    private function available(Group $g, Student $s): void
    {
        abort_unless($s->status === 'active' && $s->user?->status === 'active', 403, 'Student is inactive.');
        $course = $g->teacherSubject;
        abort_unless(in_array($g->status, ['forming', 'active'], true) && $course?->status === 'active'
            && $course->subject?->status === 'active' && $course->teacher?->status === 'active'
            && $course->teacher?->user?->status === 'active', 409, 'Group is unavailable.');
    }

    public function submit(Group $group, Student $student, ?string $message): array
    {
        $teacherId = $group->teacherSubject->teacher_id;

        return DB::transaction(function () use ($group, $student, $message, $teacherId) {
            $g = $this->group($group, $teacherId);
            $s = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $this->available($g, $s);
            abort_if($g->groupStudents()->where('student_id', $s->id)->where('status', 'active')->lockForUpdate()->get()->isNotEmpty(), 409, 'Student is already a member.');
            $old = GroupJoinRequest::where('group_id', $g->id)->where('student_id', $s->id)->where('status', 'pending')->lockForUpdate()->first();
            if ($old) {
                return [$old, false];
            }
            if ($g->groupStudents()->where('status', 'active')->lockForUpdate()->get()->count() >= $g->capacity) {
                throw new HttpResponseException(response()->json(['status' => false, 'code' => 'GROUP_FULL', 'message' => 'Group is full.'], 409));
            }
            app(GroupMembershipRules::class)->validate($g, $s);
            $join = GroupJoinRequest::create(['group_id' => $g->id, 'student_id' => $s->id, 'message' => $message, 'status' => 'pending', 'pending_guard' => 1]);
            $this->audit('group_join_request.created', $join, $s->user_id);
            Notification::create(['user_id' => $g->teacherSubject->teacher->user_id, 'type' => 'group_join_requested', 'title' => 'طلب انضمام للمجموعة', 'body' => 'يوجد طلب انضمام جديد بانتظار مراجعتك.', 'data' => ['group_id' => $g->id, 'join_request_id' => $join->id]]);

            return [$join, true];
        }, 3);
    }

    public function decide(Group $group, GroupJoinRequest $seed, string $decision, ?string $note, int $actor): GroupJoinRequest
    {
        $teacherId = $group->teacherSubject->teacher_id;

        return DB::transaction(function () use ($group, $seed, $decision, $note, $actor, $teacherId) {
            $g = $this->group($group, $teacherId);
            $s = Student::whereKey($seed->student_id)->lockForUpdate()->firstOrFail();
            $join = GroupJoinRequest::where('group_id', $g->id)->whereKey($seed->id)->lockForUpdate()->firstOrFail();
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            if ($join->status !== 'pending') {
                abort_unless($join->status === $status, 409, 'Request already decided.');

                return $join;
            }
            if ($decision === 'approve') {
                $this->available($g, $s);
                $existing = $g->groupStudents()->where('student_id', $s->id)->where('status', 'active')->lockForUpdate()->get()->isNotEmpty();
                if (! $existing) {
                    if ($g->groupStudents()->where('status', 'active')->lockForUpdate()->get()->count() >= $g->capacity) {
                        throw new HttpResponseException(response()->json(['status' => false, 'code' => 'GROUP_FULL', 'message' => 'Group is full.'], 409));
                    }
                    app(GroupMembershipRules::class)->validate($g, $s);
                    GroupStudent::updateOrCreate(['group_id' => $g->id, 'student_id' => $s->id], ['status' => 'active', 'joined_at' => now(), 'left_at' => null]);
                }
            }
            $this->finalize($join, $status, $note, $actor);

            return $join->fresh();
        }, 3);
    }

    /** Called inside the existing teacher/group/student transaction. */
    public function approveAfterDirectAdd(Group $group, Student $student, int $actor): void
    {
        $join = GroupJoinRequest::where('group_id', $group->id)->where('student_id', $student->id)->where('status', 'pending')->lockForUpdate()->first();
        if ($join) {
            $this->finalize($join, 'approved', 'Approved through direct group membership.', $actor);
        }
    }

    private function finalize(GroupJoinRequest $join, string $status, ?string $note, int $actor): void
    {
        $join->update(['status' => $status, 'pending_guard' => null, 'review_note' => $note, 'reviewed_by' => $actor, 'reviewed_at' => now()]);
        $this->audit('group_join_request.'.$status, $join, $actor);
        Notification::create(['user_id' => $join->student->user_id, 'type' => 'group_join_'.$status, 'title' => $status === 'approved' ? 'تم قبول طلب الانضمام' : 'نتيجة طلب الانضمام', 'body' => $status === 'approved' ? 'تمت إضافتك للمجموعة. الدفع الحضوري عند الأستاذ.' : ($note ?: 'لم يقبل الأستاذ طلب الانضمام.'), 'data' => ['group_id' => $join->group_id, 'join_request_id' => $join->id]]);
    }

    private function audit(string $action, GroupJoinRequest $join, int $actor): void
    {
        AuditLog::create(['user_id' => $actor, 'action' => $action, 'entity_type' => GroupJoinRequest::class, 'entity_id' => $join->id, 'metadata_json' => ['group_id' => $join->group_id, 'student_id' => $join->student_id, 'status' => $join->status], 'created_at' => now()]);
    }
}
