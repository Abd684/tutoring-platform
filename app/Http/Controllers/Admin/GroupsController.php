<?php

namespace App\Http\Controllers\Admin;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Services\GroupMembershipRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupsController extends AdminController
{
    public function index(Request $request)
    {
        $data = $this->filters($request);
        $query = Group::with(['teacherSubject.teacher.user', 'teacherSubject.subject'])->withCount(['groupEnrollments as active_members_count' => fn ($q) => $q->where('status', 'active')]);
        if (! empty($data['search'])) {
            $query->where('name', 'like', '%'.$data['search'].'%');
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['teacher_id'])) {
            $query->whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $data['teacher_id']));
        }
        if ($request->boolean('export')) {
            return $this->csv($query->orderBy('id'), ['المعرف' => 'id', 'المجموعة' => 'name', 'المدرس' => 'teacherSubject.teacher.user.name', 'المادة' => 'teacherSubject.subject.name', 'الحالة' => 'status', 'السعة' => 'capacity'], 'groups');
        }
        $items = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $teachers = Teacher::with('user')->get();
        $stats = ['total' => Group::count(), 'active' => Group::whereIn('status', ['active', 'forming'])->count(), 'closed' => Group::where('status', 'closed')->count()];

        return view('admin.groups.index', compact('items', 'teachers', 'stats'));
    }

    public function create()
    {
        return $this->form(new Group(['capacity' => 12, 'status' => 'forming']));
    }

    public function edit(Group $group)
    {
        return $this->form($group);
    }

    private function form(Group $group)
    {
        $courses = TeacherSubject::with(['teacher.user', 'subject'])->where('status', 'active')->get();

        return view('admin.groups.form', compact('group', 'courses'));
    }

    public function store(Request $request)
    {
        return $this->save($request, new Group);
    }

    public function update(Request $request, Group $group)
    {
        return $this->save($request, $group);
    }

    private function save(Request $request, Group $group)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'], 'capacity' => ['required', 'integer', 'between:1,100000'], 'status' => ['required', 'in:forming,active,closed']]);
        DB::transaction(function () use ($group, $data) {
            $course = TeacherSubject::with(['teacher.user', 'subject'])->findOrFail($data['teacher_subject_id']);
            Teacher::whereKey($course->teacher_id)->lockForUpdate()->firstOrFail();
            if ($group->exists) {
                $group = Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
                if ($group->teacher_subject_id !== (int) $data['teacher_subject_id']) {
                    throw ValidationException::withMessages(['teacher_subject_id' => ['لا يمكن نقل المجموعة لمادة أخرى حفاظاً على الاشتراكات والجلسات.']]);
                }
                if ($group->groupEnrollments()->where('status', 'active')->count() > $data['capacity']) {
                    throw ValidationException::withMessages(['capacity' => ['السعة أقل من عدد العضويات الفعالة.']]);
                }
            }
            if ($course->status !== 'active' || $course->subject?->status !== 'active' || $course->teacher?->status !== 'active' || $course->teacher->user?->status !== 'active') {
                throw ValidationException::withMessages(['teacher_subject_id' => ['المادة أو المدرس غير فعال.']]);
            }
            $group->fill($data)->save();
            $this->audit('group.saved', $group);
        });

        return redirect()->route('admin.groups.index')->with('success', 'تم حفظ المجموعة.');
    }

    public function show(Group $group)
    {
        $group->load(['teacherSubject.teacher.user', 'teacherSubject.subject', 'groupEnrollments.enrollment.student.user', 'sessions.attendance']);
        $enrollments = Enrollment::with('student.user')->where('teacher_id', $group->teacherSubject->teacher_id)->where('enrollable_type', 'teacher_subject')->where('enrollable_id', $group->teacher_subject_id)->where('status', 'active')->get();

        return view('admin.groups.show', compact('group', 'enrollments'));
    }

    public function add(Request $request, Group $group)
    {
        $data = $request->validate(['enrollment_id' => ['required', 'integer', 'exists:enrollments,id']]);
        DB::transaction(function () use ($group, $data) {
            Teacher::whereKey($group->teacherSubject->teacher_id)->lockForUpdate()->firstOrFail();
            $group = Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $enrollment = Enrollment::whereKey($data['enrollment_id'])->lockForUpdate()->firstOrFail();
            app(GroupMembershipRules::class)->validate($group, $enrollment);
            if (! in_array($group->status, ['forming', 'active'], true) || $group->groupEnrollments()->where('status', 'active')->count() >= $group->capacity) {
                throw ValidationException::withMessages(['enrollment_id' => ['المجموعة مغلقة أو ممتلئة.']]);
            }
            $existing = $group->groupEnrollments()->where('enrollment_id', $enrollment->id)->first();
            if ($existing?->status === 'active') {
                throw ValidationException::withMessages(['enrollment_id' => ['الطالب عضو بالفعل بهذا الاشتراك.']]);
            }
            $member = GroupEnrollment::updateOrCreate(['group_id' => $group->id, 'enrollment_id' => $enrollment->id], ['status' => 'active', 'joined_at' => now(), 'left_at' => null]);
            $this->audit('group.member_added', $member);
        });

        return back()->with('success', 'تمت إضافة العضوية.');
    }

    public function remove(Group $group, GroupEnrollment $membership)
    {
        abort_unless($membership->group_id === $group->id, 404);
        DB::transaction(function () use ($group, $membership) {
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $membership = GroupEnrollment::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $membership->update(['status' => 'removed', 'left_at' => now()]);
            $this->audit('group.member_removed',$membership);
        });

        return back()->with('success','تم إنهاء العضوية مع حفظ سجلها.');
    }
}
