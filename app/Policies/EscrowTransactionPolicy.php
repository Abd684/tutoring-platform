<?php

namespace App\Policies;

class EscrowTransactionPolicy extends ResourcePolicy
{
    protected ?string $teacherPath = 'teacher_id';
}
