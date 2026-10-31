<?php

namespace App\Policies;

class IndividualOfferPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'teacherSubject.teacher_id';
}
