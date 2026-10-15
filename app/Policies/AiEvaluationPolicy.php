<?php

namespace App\Policies;

class AiEvaluationPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'answer.attempt.student.user_id';

    protected ?string $teacherPath = 'answer.question.quiz.content.lesson.unit.teacherSubject.teacher_id';
}
