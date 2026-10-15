<?php

namespace App\Policies;

class StudentPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'user_id';
}
