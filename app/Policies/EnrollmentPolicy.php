<?php

namespace App\Policies;

class EnrollmentPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';

    protected ?string $teacherPath = 'teacher_id';
}
