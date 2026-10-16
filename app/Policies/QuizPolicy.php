<?php

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class QuizPolicy extends ResourcePolicy
{
    public function access(User $user, Quiz $quiz): Response
    {
        return $quiz->status === 'published' && $quiz->content
            ? app(ContentPolicy::class)->access($user, $quiz->content)
            : Response::denyAsNotFound();
    }

    protected ?string $teacherPath = 'content.lesson.unit.teacherSubject.teacher_id';
}
