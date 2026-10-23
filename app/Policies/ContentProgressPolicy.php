<?php

namespace App\Policies;

class ContentProgressPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';
}
