<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceSession;
use App\Models\PushRegistration;
use App\Models\StudentDevice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class PushRegistrationController extends ApiController
{
    /**
     * Register/refresh the FCM address belonging to the currently authenticated app.
     * Never accept user_id or device_id from the client.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'min:20', 'max:4096'],
            'user_id' => ['prohibited'], 'device_id' => ['prohibited'],
            'platform' => ['required', 'in:android,ios,web'],
        ]);

        $user = $request->user();
        $deviceId = $this->deviceIdFor($user);
        $hash = hash('sha256', $data['fcm_token']);

        try {
            DB::transaction(function () use ($user, $deviceId, $hash, $data): void {
                // Serializes competing registrations by the same account.
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                $registration = PushRegistration::query()
                    ->where('token_hash', $hash)
                    ->lockForUpdate()
                    ->first();

                // An FCM address registered to a different user is never silently claimed.
                abort_if($registration && (int) $registration->user_id !== (int) $user->id,
                    409, 'This notification token is registered to a different account.');

                $registration ??= new PushRegistration;
                $registration->fill([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'token_hash' => $hash,
                    // PushRegistration's encrypted cast encrypts this before writing to MySQL.
                    'token_ciphertext' => $data['fcm_token'],
                    'platform' => $data['platform'],
                    'is_active' => true,
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                ]);
                $registration->save();

                if ($user->role === 'student') {
                    // One active installation for the student, mirroring device binding.
                    PushRegistration::query()
                        ->where('user_id', $user->id)
                        ->where('id', '!=', $registration->id)
                        ->delete();
                } else {
                    // Prevent unlimited growth when a teacher's FCM tokens rotate.
                    $keep = PushRegistration::query()
                        ->where('user_id', $user->id)
                        ->orderByDesc('last_seen_at')
                        ->orderByDesc('id')
                        ->limit(5)
                        ->pluck('id');
                    PushRegistration::query()
                        ->where('user_id', $user->id)
                        ->whereNotIn('id', $keep)
                        ->delete();
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Rare case: two accounts register the same token concurrently.
            return $this->businessError('Notification token registration conflict. Retry or contact support.', 409);
        }

        return $this->success(['registered' => true], 'Push token registered.');
    }

    /** Remove this app's FCM address. Repeat calls have the same result. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fcm_token' => ['required', 'string', 'min:20', 'max:4096'],
            'user_id' => ['prohibited'], 'device_id' => ['prohibited'],
        ]);
        $user = $request->user();
        $deviceId = $this->deviceIdFor($user);

        $query = PushRegistration::query()
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $data['fcm_token']));

        if ($user->role === 'student') {
            $query->where('device_id', $deviceId);
        }

        // We do not retain old FCM tokens: keeps storage small and stops deliveries.
        $query->delete();

        return $this->success(['registered' => false], 'Push token unregistered.');
    }

    /**
     * Resolve the student device from the verified Sanctum device-session token,
     * not from a device identifier supplied by Flutter.
     * Route must also use auth:sanctum + role + student.device middleware.
     */
    private function deviceIdFor(?User $user): ?int
    {
        abort_unless($user && $user->status === 'active', 403, 'Active account required.');
        abort_unless(in_array($user->role, ['student', 'teacher'], true), 403);

        if ($user->role === 'teacher') {
            abort_unless($user->teacher()->exists(), 403, 'Teacher account required.');

            return null;
        }

        $student = $user->student;
        abort_unless($student && $student->status === 'active', 403, 'Active student required.');

        $accessToken = $user->currentAccessToken();
        abort_unless($accessToken instanceof PersonalAccessToken
            && preg_match('/^student_device_(\d+)_session_(\d+)$/', $accessToken->name, $matches),
            401, 'Device-bound student session required.');

        $studentDevice = StudentDevice::query()->with('device')
            ->whereKey((int) $matches[1])
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->where('is_active', true)
            ->first();

        abort_unless($studentDevice && $studentDevice->device
            && $studentDevice->device->status !== 'blocked'
            && DeviceSession::query()
                ->whereKey((int) $matches[2])
                ->where('student_device_id', $studentDevice->id)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->exists(),
            401, 'Student device session is no longer active.');

        return (int) $studentDevice->device_id;
    }
}
