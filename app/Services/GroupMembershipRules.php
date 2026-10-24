<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Session;
use App\Models\Student;
use App\Models\TeacherSubject;
use Illuminate\Validation\ValidationException;

class GroupMembershipRules
{
    public function validate(Group $group, Enrollment $enrollment): void
    {
        Student::whereKey($enrollment->student_id)->lockForUpdate()->firstOrFail();
        if ($enrollment->enrollable_type !== (new TeacherSubject)->getMorphClass()
            || (int) $enrollment->enrollable_id !== (int) $group->teacher_subject_id
            || (int) $enrollment->teacher_id !== (int) $group->teacherSubject->teacher_id
            || ! app(SessionRules::class)->active($enrollment, now(), now())) {
            throw ValidationException::withMessages(['enrollment_id' => ['An active full-subject enrollment for the same group subject and teacher is required.']]);
        }
        foreach (Session::where('group_id', $group->id)->whereIn('status', ['scheduled', 'ongoing'])->get() as $session) {
            abort_if(app(PaidLessonBookings::class)->holdConflicts($session->teacher_id, [$enrollment->student_id], $session->starts_at, $session->ends_at), 409, 'Student has an overlapping paid lesson hold.');
            if (! app(SessionRules::class)->active($enrollment, $session->starts_at, $session->ends_at)) {
                throw ValidationException::withMessages(['enrollment_id' => ['Enrollment must cover all scheduled group sessions.']]);
            }
            abort_if(app(SessionEligibility::class)->conflicts($enrollment->student_id, $session)->where(fn ($q) => $q->whereNull('group_id')->orWhere('group_id', '!=', $group->id))->exists(), 409, 'Student has an overlapping session.');
        }
    }
}
