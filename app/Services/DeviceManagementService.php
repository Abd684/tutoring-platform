<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\DeviceSession;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceManagementService
{
    public function findOrCreateDevice(array $validated): Device
    {
        $device = Device::firstOrCreate(['device_uuid' => $validated['device_uuid']], [
            'platform' => $validated['platform'], 'status' => 'trusted',
            'manufacturer' => $validated['manufacturer'] ?? null, 'model' => $validated['model'] ?? null,
            'os_version' => $validated['os_version'] ?? null, 'app_version' => $validated['app_version'] ?? null,
            'public_key' => $validated['public_key'] ?? null,
        ]);
        $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();

        if ($device->status === 'blocked') {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'This device is blocked.',
                    'code' => 'DEVICE_BLOCKED',
                ], 403)
            );
        }

        $device->update([
            'platform' => $validated['platform'],
            'manufacturer' => $validated['manufacturer'] ?? $device->manufacturer,
            'model' => $validated['model'] ?? $device->model,
            'os_version' => $validated['os_version'] ?? $device->os_version,
            'app_version' => $validated['app_version'] ?? $device->app_version,
            'public_key' => $validated['public_key'] ?? $device->public_key,
        ]);

        return $device;
    }

    /** Called inside a transaction holding the student lock. */
    public function loginBinding(Student $student, Device $device): StudentDevice
    {
        return DB::transaction(function () use ($student, $device) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $active = StudentDevice::where('student_id', $student->id)->where('status', 'active')->where('is_active', true)->lockForUpdate()->first();
            if ($active && (int) $active->device_id !== (int) $device->id) {
                throw new HttpResponseException(response()->json(['status' => false, 'message' => 'Device transfer is required.', 'code' => 'DEVICE_TRANSFER_REQUIRED'], 409));
            }
            if ($active) {
                $active->update(['last_seen_at' => now()]);

                return $active;
            }
            $binding = $this->activate($student, $device);
            DeviceEvent::create(['student_device_id' => $binding->id, 'event_type' => 'device_registered', 'metadata_json' => ['source' => 'login'], 'created_at' => now()]);

            return $binding;
        }, 3);
    }

    /** Called inside a transaction holding the student lock. */
    public function activate(Student $student, Device $device): StudentDevice
    {
        return DB::transaction(function () use ($student, $device) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            abort_if($device->status === 'blocked', 403, 'This device is blocked.');
            $ids = StudentDevice::where('student_id', $student->id)->lockForUpdate()->pluck('id');
            DeviceSession::whereIn('student_device_id', $ids)->where('status', 'active')->update([
                'status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'device_transfer']);
            StudentDevice::whereIn('id', $ids)->update(['status' => 'revoked', 'is_active' => false, 'revoked_at' => now(), 'revoke_reason' => 'device_transfer']);
            $student->user->tokens()->delete();

            return StudentDevice::updateOrCreate(['student_id' => $student->id, 'device_id' => $device->id], [
                'status' => 'active', 'is_active' => true, 'activated_at' => now(), 'last_seen_at' => now(), 'revoked_at' => null, 'revoke_reason' => null]);
        }, 3);
    }

    public function issueStudentSession(
        User $user,
        StudentDevice $studentDevice,
        Request $request,
        string $eventType,
        int $refreshDays,
        int $accessMinutes
    ): array {
        DeviceSession::where('student_device_id', $studentDevice->id)
            ->where('status', 'active')
            ->update([
                'status' => 'revoked',
                'last_activity_at' => now(),
                'revoked_at' => now(),
                'revoke_reason' => 'new_login',
            ]);

        $user->tokens()->delete();

        $refreshToken = Str::random(80);
        $refreshExpiresAt = now()->addDays($refreshDays);

        $deviceSession = DeviceSession::create([
            'student_device_id' => $studentDevice->id,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'status' => 'active',
            'last_activity_at' => now(),
            'expires_at' => $refreshExpiresAt,
            'revoked_at' => null,
            'revoke_reason' => null,
        ]);

        $accessTokenExpiresAt = now()->addMinutes($accessMinutes);

        $newToken = $user->createToken(
            'student_device_'.$studentDevice->id.'_session_'.$deviceSession->id,
            ['student'],
            $accessTokenExpiresAt
        );

        $this->recordDeviceEvent($studentDevice, $eventType, $request, [
            'device_session_id' => $deviceSession->id,
        ]);

        return [
            'access_token' => $newToken->plainTextToken,
            'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
            'refresh_token' => $refreshToken,
            'refresh_token_expires_at' => $refreshExpiresAt->toDateTimeString(),
            'device_session' => $deviceSession,
        ];
    }

    private function recordDeviceEvent(
        StudentDevice $studentDevice,
        string $eventType,
        Request $request,
        array $metadata = []
    ): void {
        DeviceEvent::create([
            'student_device_id' => $studentDevice->id,
            'event_type' => $eventType,
            'metadata_json' => array_merge([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], $metadata),
            'created_at' => now(),
        ]);
    }
}
