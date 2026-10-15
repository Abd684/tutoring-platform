<?php

namespace App\Policies;

class PdfAssetPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'contentAsset.content.lesson.unit.teacherSubject.teacher_id';
}
