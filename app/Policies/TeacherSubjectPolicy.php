<?php

namespace App\Policies;

class TeacherSubjectPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'teacher_id';
}
