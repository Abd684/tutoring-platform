<?php

namespace App\Policies;

class LessonCompletionPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'student.user_id';
}
