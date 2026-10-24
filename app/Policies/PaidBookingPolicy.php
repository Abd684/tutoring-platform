<?php

namespace App\Policies;

class PaidBookingPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';
}
