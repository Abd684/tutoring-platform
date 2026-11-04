<?php

namespace App\Policies;

use App\Models\NotificationCampaign;
use App\Models\User;

class NotificationCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'company_admin' && $user->status === 'active';
    }

    public function manage(User $user, NotificationCampaign $campaign): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
