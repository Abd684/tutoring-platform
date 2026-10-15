<?php

namespace App\Policies;

class DeviceEventPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'studentDevice.student.user_id';
}
