<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use App\Services\StudentGroupAccess;
use Illuminate\Auth\Access\Response;

class GroupPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'teacherSubject.teacher_id';

    public function viewMembers(User $user, Group $group): Response
    {
        return $user->role === 'student' && $user->status === 'active' && $user->student
            && app(StudentGroupAccess::class)->canReadMembers($user->student, $group)
                ? Response::allow() : Response::denyAsNotFound();
    }
}
