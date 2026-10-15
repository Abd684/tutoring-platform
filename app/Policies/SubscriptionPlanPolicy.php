<?php

namespace App\Policies;

use App\Models\User;

class SubscriptionPlanPolicy extends ResourcePolicy
{
    public function subscribe(User $user): bool
    {
        return $this->teacher($user);
    }
}
