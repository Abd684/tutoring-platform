<?php

namespace App\Policies;

class PaymentPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'payer_id';
}
