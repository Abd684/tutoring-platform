<?php

namespace App\Policies;

use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\ContentAccess;
use Illuminate\Auth\Access\Response;

class TeacherSubjectPolicy extends ResourcePolicy
{
    public function createOwn(User $user): Response
    {
        return $this->teacher($user)
            ? Response::allow() : Response::deny('Active teacher account required.');
    }

    public function viewOwnAny(User $user): bool
    {
        return $this->teacher($user);
    }

    public function accessLearning(User $user, TeacherSubject $course): Response
    {
        return $this->accessStudent($user) && app(ContentAccess::class)->allowsCourse($user->student, $course)
            ? Response::allow() : Response::denyAsNotFound();
    }

    protected ?string $teacherPath = 'teacher_id';
}
