<?php

namespace App\Policies;

class GroupStudentPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';

    protected ?string $teacherPath = 'group.teacherSubject.teacher_id';
}
