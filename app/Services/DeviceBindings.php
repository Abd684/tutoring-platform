<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceSession;
use App\Models\RefreshToken;
use App\Models\Student;
use App\Models\StudentDevice;
use Illuminate\Support\Facades\DB;

class DeviceBindings
{
    public function activate(Student $student, Device $device): StudentDevice
    {
        return DB::transaction(function () use ($student, $device) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            abort_unless($student->status === 'active' && $student->user?->status === 'active', 403);
            abort_if($device->status === 'blocked', 403, 'This device is blocked.');
            $ids = StudentDevice::where('student_id', $student->id)->where(fn ($q) => $q->where('status', 'active')->orWhere('is_active', true))->pluck('id');
            $sessions = DeviceSession::whereIn('student_device_id', $ids)->pluck('id');
            DeviceSession::whereIn('id', $sessions)->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'device_transfer']);
            RefreshToken::where('user_id', $student->user_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            StudentDevice::whereIn('id', $ids)->update(['status' => 'revoked', 'is_active' => false, 'revoked_at' => now(), 'revoke_reason' => 'device_transfer']);
            $student->user->tokens()->delete();

            return StudentDevice::updateOrCreate(['student_id' => $student->id, 'device_id' => $device->id],
                ['status' => 'active', 'is_active' => true, 'activated_at' => now(), 'last_seen_at' => now(), 'revoked_at' => null, 'revoke_reason' => null]);
        }, 3);
    }
}
