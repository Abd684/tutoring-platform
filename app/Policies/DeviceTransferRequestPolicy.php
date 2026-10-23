<?php

namespace App\Policies;

use App\Models\DeviceTransferRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceTransferRequestPolicy extends ResourcePolicy
{
    public function decide(User $user, DeviceTransferRequest $transfer, ?int $bindingId = null): Response
    {
        if ($bindingId === null) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken
                && preg_match('/^student_device_(\d+)_session_\d+$/', $token->name, $matches)) {
                $bindingId = (int) $matches[1];
            }
        }

        return $this->accessStudent($user) && (int) $transfer->student_id === (int) $user->student->id
            && (int) $transfer->source_student_device_id === $bindingId
            && $transfer->sourceStudentDevice?->status === 'active' && $transfer->sourceStudentDevice?->is_active
            ? Response::allow() : Response::denyAsNotFound();
    }
}
