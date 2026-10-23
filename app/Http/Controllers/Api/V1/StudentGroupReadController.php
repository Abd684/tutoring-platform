<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\Student;
use App\Services\StudentGroupAccess;
use Illuminate\Http\Request;

class StudentGroupReadController extends ApiController
{
    public function index(Request $request, StudentGroupAccess $access)
    {
        $this->authorize('accessStudent', GroupEnrollment::class);
        $student = $request->user()->student;
        $data = $request->validate(['scope' => ['sometimes', 'in:active,history,all'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $scope = $data['scope'] ?? 'active';
        $activeGroups = Group::select('id')->whereIn('status', ['active', 'forming'])->whereHas('teacherSubject', fn ($q) => $access->visibleCourses($q))
            ->whereIn('id', $access->memberships($student)->select('group_id'));
        $query = Group::whereHas('groupEnrollments.enrollment', fn ($q) => $q->where('student_id', $student->id));
        if ($scope === 'active') {
            $query->whereIn('id', $activeGroups);
        }
        if ($scope === 'history') {
            $query->whereNotIn('id', $activeGroups);
        }
        $query->with(['teacherSubject.subject', 'teacherSubject.teacher.user:id,name,avatar_path',
            'groupEnrollments' => fn ($q) => $q->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))->with('enrollment')->orderByDesc('id')]);
        $count = $access->memberships()->join('enrollments as counted_members', 'counted_members.id', '=', 'group_enrollments.enrollment_id')
            ->whereColumn('group_enrollments.group_id', 'groups.id')->selectRaw('COUNT(DISTINCT counted_members.student_id)');
        $query->addSelect(['active_members_count' => $count]);
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 20);
        $eligible = $access->memberships($student)->whereIn('group_id', $page->getCollection()->pluck('id'))->pluck('id')->flip();
        $readable = Group::whereIn('id', $activeGroups)->whereIn('id', $page->getCollection()->pluck('id'))->pluck('id')->flip();
        $page->setCollection($page->getCollection()->map(function ($group) use ($eligible, $readable) {
            $mine = $group->groupEnrollments->first(fn ($membership) => $eligible->has($membership->id)) ?? $group->groupEnrollments->first();
            $course = $group->teacherSubject;
            $teacher = $course?->teacher;
            $canRead = $readable->has($group->id);
            $enrollment = $mine?->enrollment;
            $effective = $mine?->status;
            if ($effective === 'active' && ! $eligible->has($mine->id)) {
                $effective = $enrollment?->status === 'pending' ? 'pending' : 'expired';
            }

            return ['group_id' => $group->id, 'name' => $group->name, 'status' => $group->status, 'capacity' => (int) $group->capacity,
                'teacher_subject_id' => $course?->id, 'subject_name' => $course?->subject?->name,
                'teacher' => $teacher ? ['id' => $teacher->id, 'name' => $teacher->user?->name, 'avatar_url' => $teacher->user?->avatar_url] : null,
                'active_members_count' => (int) $group->active_members_count, 'remaining_seats' => max(0, (int) $group->capacity - (int) $group->active_members_count),
                'membership_status' => $effective, 'membership_record_status' => $mine?->status, 'joined_at' => $mine?->joined_at?->toIso8601String(),
                'left_at' => $mine?->left_at?->toIso8601String(), 'can_view_members' => $canRead,
                'members_endpoint' => $canRead ? '/student/groups/'.$group->id.'/members' : null];
        }));

        return $this->paginated($page);
    }

    public function members(Request $request, Group $group, StudentGroupAccess $access)
    {
        $this->authorize('viewMembers', $group);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $ids = $access->memberships()->where('group_id', $group->id)->join('enrollments as member_enrollments', 'member_enrollments.id', '=', 'group_enrollments.enrollment_id')->select('member_enrollments.student_id');
        $page = Student::whereIn('id', $ids)->with('user:id,name,avatar_path')->orderBy('id')->paginate($data['per_page'] ?? 20);
        $page->setCollection($page->getCollection()->map(fn ($member) => ['member_id' => $member->id, 'display_name' => $member->user->name, 'avatar_url' => $member->user->avatar_url]));

        return $this->paginated($page);
    }
}
