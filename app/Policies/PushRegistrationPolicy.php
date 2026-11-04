<?php

namespace App\Policies;

use App\Models\User;

// FCM addresses are credential material, not an administrative CRUD resource.
// Registration/removal uses the authenticated installation-specific controller.
class PushRegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }
}
