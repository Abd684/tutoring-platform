<?php

namespace App\Policies;

class StudentDevicePolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';
}
