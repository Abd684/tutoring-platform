<?php

namespace App\Policies;

class AnswerPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'attempt.student.user_id';

    protected ?string $teacherPath = 'question.quiz.content.lesson.unit.teacherSubject.teacher_id';
}
