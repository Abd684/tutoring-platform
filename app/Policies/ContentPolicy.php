<?php

namespace App\Policies;

class ContentPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'lesson.unit.teacherSubject.teacher_id';
}
