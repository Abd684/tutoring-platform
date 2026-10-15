<?php

namespace App\Policies;

use App\Models\TeacherAvailability;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TeacherAvailabilityPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveTeacher($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveTeacher($user);
    }

    public function view(
        User $user,
        TeacherAvailability $teacherAvailability
    ): Response {
        return $this->owns($user, $teacherAvailability);
    }

    public function update(
        User $user,
        TeacherAvailability $teacherAvailability
    ): Response {
        return $this->owns($user, $teacherAvailability);
    }

    public function delete(
        User $user,
        TeacherAvailability $teacherAvailability
    ): Response {
        return $this->owns($user, $teacherAvailability);
    }

    private function owns(
        User $user,
        TeacherAvailability $teacherAvailability
    ): Response {
        if (! $this->isActiveTeacher($user)) {
            return Response::deny('Active teacher account required.');
        }

        if (
            (int) $teacherAvailability->teacher_id !==
            (int) $user->teacher->id
        ) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    private function isActiveTeacher(User $user): bool
    {
        return $user->status === 'active'
            && $user->role === 'teacher'
            && $user->teacher !== null
            && $user->teacher->status === 'active';
    }
}
