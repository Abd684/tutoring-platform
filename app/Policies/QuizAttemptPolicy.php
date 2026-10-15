<?php

namespace App\Policies;

class QuizAttemptPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';

    protected ?string $teacherPath = 'quiz.content.lesson.unit.teacherSubject.teacher_id';
}
