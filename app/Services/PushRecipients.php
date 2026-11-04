<?php

namespace App\Services;

use App\Models\DeviceSession;
use App\Models\PushRegistration;
use App\Models\StudentDevice;

class PushRecipients
{
    public function eligible(PushRegistration $registration): bool
    {
        $user = $registration->user;
        if (! $registration->is_active || $registration->revoked_at || ! $user || $user->status !== 'active') {
            return false;
        }
        if ($user->role === 'teacher') {
            return $user->teacher?->status === 'active';
        }
        if ($user->role !== 'student' || $user->student?->status !== 'active' || ! $registration->device_id) {
            return false;
        }
        $binding = StudentDevice::where('student_id', $user->student->id)->where('device_id', $registration->device_id)
            ->where('status', 'active')->where('is_active', true)->whereHas('device', fn ($q) => $q->where('status', '!=', 'blocked'))->first();

        return $binding && DeviceSession::where('student_device_id', $binding->id)->where('status', 'active')->where('expires_at', '>', now())->exists();
    }
}
