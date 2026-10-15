<?php

namespace App\Policies;

class LessonPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'unit.teacherSubject.teacher_id';
}
