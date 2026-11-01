<?php

namespace App\Policies;

use App\Models\GroupJoinRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GroupJoinRequestPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'group.teacherSubject.teacher_id';

    protected ?string $ownerPath = 'student.user_id';

    public function review(User $user, GroupJoinRequest $joinRequest): Response
    {
        return $this->manage($user, $joinRequest);
    }
}
