<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends ResourcePolicy
{
    public function viewReceipt(User $user, Payment $payment): bool
    {
        return $this->accessStudent($user) && (int) $payment->payer_id === (int) $user->id;
    }

    protected ?string $ownerPath = 'payer_id';
}
