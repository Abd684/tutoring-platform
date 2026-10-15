<?php

namespace App\Policies;

class WalletPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'user_id';
}
