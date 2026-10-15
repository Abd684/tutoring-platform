<?php

namespace App\Policies;

class DeviceSessionPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'studentDevice.student.user_id';
}
