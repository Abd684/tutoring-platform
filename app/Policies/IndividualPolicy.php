<?php

namespace App\Policies;

class IndividualPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';

    protected ?string $teacherPath = 'teacherSubject.teacher_id';
}
