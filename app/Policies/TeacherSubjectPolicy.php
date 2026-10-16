<?php

namespace App\Policies;

use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\ContentAccess;
use Illuminate\Auth\Access\Response;

class TeacherSubjectPolicy extends ResourcePolicy
{
    public function accessLearning(User $user, TeacherSubject $course): Response
    {
        return $this->accessStudent($user) && app(ContentAccess::class)->allowsCourse($user->student, $course)
            ? Response::allow() : Response::denyAsNotFound();
    }

    protected ?string $teacherPath = 'teacher_id';
}
