<?php

namespace App\Policies;

class QuizPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'content.lesson.unit.teacherSubject.teacher_id';
}
