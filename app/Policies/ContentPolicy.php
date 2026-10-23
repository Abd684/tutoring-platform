<?php

namespace App\Policies;

use App\Models\Content;
use App\Models\User;
use App\Services\ContentAccess;
use Illuminate\Auth\Access\Response;

class ContentPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'lesson.unit.teacherSubject.teacher_id';

    public function access(User $user, Content $content): Response
    {
        return $user->role === 'student' && $user->student && app(ContentAccess::class)->allows($user->student, $content)
            ? Response::allow() : Response::denyAsNotFound();
    }
}
