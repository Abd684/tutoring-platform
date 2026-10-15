<?php

namespace App\Policies;

class QuestionPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'quiz.content.lesson.unit.teacherSubject.teacher_id';
}
