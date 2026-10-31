<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Student;

class StudentGroupAccess
{
    public function memberships(?Student $student = null)
    {
        return GroupStudent::where('group_students.status', 'active')->when($student, fn ($q) => $q->where('student_id', $student->id))
            ->whereHas('student', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')));
    }

    public function visibleCourses($query): void
    {
        $query->where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active'))->whereHas('teacher', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')));
    }

    public function canReadMembers(Student $student, Group $group): bool
    {
        return $student->status === 'active' && $student->user?->status === 'active' && in_array($group->status, ['active', 'forming'], true)
            && Group::whereKey($group->id)->whereHas('teacherSubject', fn ($q) => $this->visibleCourses($q))->exists() && $this->memberships($student)->where('group_id', $group->id)->exists();
    }
}
