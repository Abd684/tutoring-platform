<?php

namespace App\Policies;

use App\Models\Content;
use App\Models\User;
use App\Services\ContentAccess;
use Illuminate\Auth\Access\Response;

class ContentPolicy extends ResourcePolicy
{
    public function access(User $user, Content $content): Response
    {
        return $this->accessStudent($user) && app(ContentAccess::class)->allows($user->student, $content)
            ? Response::allow() : Response::denyAsNotFound();
    }

    protected ?string $teacherPath = 'lesson.unit.teacherSubject.teacher_id';
}
