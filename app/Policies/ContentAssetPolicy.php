<?php

namespace App\Policies;

class ContentAssetPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'content.lesson.unit.teacherSubject.teacher_id';
}
