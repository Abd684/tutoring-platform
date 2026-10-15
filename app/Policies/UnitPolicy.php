<?php

namespace App\Policies;

class UnitPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'teacherSubject.teacher_id';
}
