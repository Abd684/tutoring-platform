<?php

namespace App\Policies;

class PdfViewingSessionPolicy extends ResourcePolicy
{
    protected ?string $ownerPath = 'user_id';
}
