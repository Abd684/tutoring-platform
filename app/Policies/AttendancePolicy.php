<?php

namespace App\Policies;

class AttendancePolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'enrollment.student.user_id';

    protected ?string $teacherPath = 'session.teacher_id';
}
