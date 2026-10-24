<?php

namespace App\Policies;

class PaymentCheckoutPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'user_id';
}
