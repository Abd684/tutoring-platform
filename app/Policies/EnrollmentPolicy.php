<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';

    protected ?string $teacherPath = 'teacher_id';

    public function pay(User $user, Enrollment $enrollment): bool
    {
        return $this->accessStudent($user) && (int) $enrollment->student_id === (int) $user->student?->id;
    }
}
