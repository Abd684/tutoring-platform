<?php

namespace App\Policies;

use App\Models\DeviceTransferRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeviceTransferRequestPolicy extends ResourcePolicy
{
    public function decide(User $user, DeviceTransferRequest $transfer, int $bindingId): Response
    {
        return $this->accessStudent($user) && (int) $transfer->student_id === (int) $user->student->id
            && (int) $transfer->source_student_device_id === $bindingId
            ? Response::allow() : Response::denyAsNotFound();
    }
}
