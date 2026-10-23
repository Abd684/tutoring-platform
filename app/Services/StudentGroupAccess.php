<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\Student;

class StudentGroupAccess
{
    public function memberships(?Student $student = null)
    {
        return GroupEnrollment::where('group_enrollments.status', 'active')->whereHas('enrollment', function ($q) use ($student) {
            $q->where('status', 'active')->where('enrollable_type', 'teacher_subject')
                ->when($student, fn ($q) => $q->where('student_id', $student->id))
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('student', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('groups as membership_group')
                    ->join('teacher_subjects as membership_course', 'membership_course.id', '=', 'membership_group.teacher_subject_id')
                    ->whereColumn('membership_group.id', 'group_enrollments.group_id')->whereColumn('membership_course.id', 'enrollments.enrollable_id')
                    ->whereColumn('membership_course.teacher_id', 'enrollments.teacher_id'));
        });
    }

    public function visibleCourses($query): void
    {
        $query->where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active'))
            ->whereHas('teacher', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')));
    }

    public function canReadMembers(Student $student, Group $group): bool
    {
        return $student->status === 'active' && $student->user?->status === 'active' && in_array($group->status, ['active', 'forming'], true)
            && Group::whereKey($group->id)->whereHas('teacherSubject', fn ($q) => $this->visibleCourses($q))->exists()
            && $this->memberships($student)->where('group_id',$group->id)->exists();
    }
}
