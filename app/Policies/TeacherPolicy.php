<?php

namespace App\Policies;

class TeacherPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'user_id';

    protected ?string $teacherPath = 'id';
}
