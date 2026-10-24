<?php

namespace App\Policies;

class RefundRequestPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'user_id';
}
