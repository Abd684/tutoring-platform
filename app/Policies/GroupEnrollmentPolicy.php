<?php

namespace App\Policies;

class GroupEnrollmentPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'enrollment.student.user_id';

    protected ?string $teacherPath = 'group.teacherSubject.teacher_id';
}
