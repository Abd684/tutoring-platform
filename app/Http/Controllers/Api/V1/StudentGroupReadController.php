<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use App\Models\GroupStudent;
use App\Services\ListSearch;
use App\Services\StudentGroupAccess;
use Illuminate\Http\Request;

class StudentGroupReadController extends ApiController
{
    public function index(Request $request, StudentGroupAccess $access)
    {
        $this->authorize('accessStudent', GroupStudent::class);
        $student = $request->user()->student;
        $data = $request->validate(['scope' => ['sometimes', 'in:active,history,all'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $scope = $data['scope'] ?? 'active';
        $activeGroups = Group::select('id')->whereIn('status', ['active', 'forming'])->whereHas('teacherSubject', fn ($q) => $access->visibleCourses($q))
            ->whereIn('id', $access->memberships($student)->select('group_id'));
        $query = Group::whereHas('groupStudents', fn ($q) => $q->where('student_id', $student->id));
        if ($scope === 'active') {
            $query->whereIn('id', $activeGroups);
        }
        if ($scope === 'history') {
            $query->whereNotIn('id', $activeGroups);
        }
        $query->with(['teacherSubject.subject', 'teacherSubject.teacher.user:id,name,avatar_path',
            'groupStudents' => fn ($q) => $q->where('student_id', $student->id)->orderByDesc('id')]);
        $count = $access->memberships()
            ->whereColumn('group_students.group_id', 'groups.id')->selectRaw('COUNT(DISTINCT group_students.student_id)');
        $query->addSelect(['active_members_count' => $count]);
        $page = $query->orderByDesc('id')->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'groups'))->paginate($data['per_page'] ?? 20);
        $eligible = $access->memberships($student)->whereIn('group_id', $page->getCollection()->pluck('id'))->pluck('id')->flip();
        $readable = Group::whereIn('id', $activeGroups)->whereIn('id', $page->getCollection()->pluck('id'))->pluck('id')->flip();
        $page->setCollection($page->getCollection()->map(function ($group) use ($eligible, $readable) {
            $mine = $group->groupStudents->first(fn ($membership) => $eligible->has($membership->id)) ?? $group->groupStudents->first();
            $course = $group->teacherSubject;
            $teacher = $course?->teacher;
            $canRead = $readable->has($group->id);

            $effective = $mine?->status;
            if ($effective === 'active' && ! $eligible->has($mine->id)) {
                $effective = 'inactive';
            }

            return ['group_id' => $group->id, 'name' => $group->name, 'status' => $group->status, 'capacity' => (int) $group->capacity,
                'session_price' => $group->session_price, 'currency' => $group->currency, 'payment_method' => 'cash_to_teacher', 'payment_required_on_platform' => false, 'teaching_scope_type' => $group->teaching_scope_type ?? 'subject', 'teaching_scope_id' => $group->teaching_scope_id ?? $group->teacher_subject_id,
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
        return app(PhysicalTeachingController::class)->members($request, $group);
    }
}
