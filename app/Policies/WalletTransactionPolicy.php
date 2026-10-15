<?php

namespace App\Policies;

class WalletTransactionPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'wallet.user_id';
}
