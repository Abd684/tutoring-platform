<?php

namespace App\Policies;

class ContentCompletionPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';
}
