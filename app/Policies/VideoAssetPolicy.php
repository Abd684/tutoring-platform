<?php

namespace App\Policies;

class VideoAssetPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'contentAsset.content.lesson.unit.teacherSubject.teacher_id';
}
