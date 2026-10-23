<?php

namespace App\Policies;

use App\Models\DeviceTransferRequest;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceTransferRequestPolicy extends ResourcePolicy
{
    public function decide(User $user, DeviceTransferRequest $transfer): bool
    {
        $token = $user->currentAccessToken();

        return $user->role === 'student' && $user->status === 'active' && $user->student?->status === 'active'
            && (int) $transfer->student_id === (int) $user->student->id && $token instanceof PersonalAccessToken
            && preg_match('/^student_device_(\d+)_session_\d+$/', $token->name, $matches)
            && (int) $matches[1] === (int) $transfer->source_student_device_id
            && $transfer->sourceStudentDevice?->status === 'active' && $transfer->sourceStudentDevice?->is_active;
    }
}
