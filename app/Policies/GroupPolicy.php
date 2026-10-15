<?php

namespace App\Policies;

class GroupPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'teacherSubject.teacher_id';
}
