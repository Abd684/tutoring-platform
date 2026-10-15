<?php

namespace App\Policies;

class TeacherSubscriptionPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'teacher_id';
}
