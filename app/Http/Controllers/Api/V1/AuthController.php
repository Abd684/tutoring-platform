<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\DeviceSession;
use App\Models\RefreshToken;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('teacher')->where('email', $validatedData['email'])->first();

        if (! $user || ! Hash::check($validatedData['password'], $user->password_hash)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Your account is inactive'], 403);
        }

        if ($user->role !== 'teacher' || ! $user->teacher) {
            return response()->json(['message' => 'Teacher account not found'], 403);
        }

        return response()->json([
            'message' => 'Login successful',
            ...$this->issueTokenPair($user),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'role' => $user->role,
                'teacher_status' => $user->teacher->status,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($request, $validatedData): void {
            RefreshToken::query()
                ->where('user_id', $request->user()->id)
                ->where('token_hash', hash('sha256', $validatedData['refresh_token']))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $request->user()->currentAccessToken()?->delete();
        });

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('teacher'));
    }

    public function refresh(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $result = DB::transaction(function () use ($validatedData): array {
            $refreshToken = RefreshToken::query()
                ->where('token_hash', hash('sha256', $validatedData['refresh_token']))
                ->lockForUpdate()
                ->first();

            if (! $refreshToken || $refreshToken->revoked_at !== null) {
                return ['error' => 'Invalid or revoked refresh token', 'status' => 401];
            }

            if ($refreshToken->expires_at->isPast()) {
                $refreshToken->update(['revoked_at' => now()]);

                return ['error' => 'Refresh token has expired', 'status' => 401];
            }

            $user = User::with('teacher')->find($refreshToken->user_id);

            if (! $user || $user->status !== 'active') {
                return ['error' => 'User account is unavailable', 'status' => 403];
            }

            if ($user->role !== 'teacher' || ! $user->teacher) {
                return ['error' => 'Teacher account not found', 'status' => 403];
            }

            $refreshToken->update(['revoked_at' => now()]);

            return $this->issueTokenPair($user);
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        return response()->json([
            'message' => 'Token refreshed successfully',
            ...$result,
        ]);
    }

    private function issueTokenPair(User $user): array
    {
        $plainRefreshToken = Str::random(80);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainRefreshToken),
            'expires_at' => now()->addDays(30),
        ]);

        return [
            'access_token' => $user->createToken('teacher_auth_token', ['*'], now()->addMinutes(15))->plainTextToken,
            'refresh_token' => $plainRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
        ];
    }

//أنشاء حساب الطالب
    public function register(Request $request)
{
    $validated = $request->validate([
        'name' => [
            'required',
            'string',
            'max:255',
        ],

        'phone' => [
            'required',
            'string',
            'max:30',
        ],

        'email' => [
            'required',
            'email',
            'max:255',
            'unique:users,email',
        ],

        'password' => [
            'required',
            'string',
            'min:8',
            'confirmed',
        ],

        'region_id' => [
            'required',
            'integer',
            'exists:regions,id',
        ],

        'grade' => [
            'required',
            'string',
            'max:100',
        ],

        'school_id' => [
            'required',
            'integer',
            Rule::exists('schools', 'id')->where(
                fn ($query) => $query->where('region_id', $request->region_id)
            ),
        ],
    ]);

    $result = DB::transaction(function () use ($validated) {

        $user = User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'password_hash' => $validated['password'],
            'role' => 'student',
            'status' => 'active',
            'region_id' => $validated['region_id'],
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'grade' => $validated['grade'],
            'school_id' => $validated['school_id'],
            'status' => 'active',
        ]);

        return [
            'user' => $user,
            'student' => $student,
        ];
    });

    return response()->json([
        'status' => true,
        'message' => 'Student account created successfully.',
        'data' => [
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'phone' => $result['user']->phone,
                'email' => $result['user']->email,
                'role' => $result['user']->role,
                'status' => $result['user']->status,
                'region_id' => $result['user']->region_id,
            ],

            'student' => [
                'id' => $result['student']->id,
                'grade' => $result['student']->grade,
                'school_id' => $result['student']->school_id,
                'status' => $result['student']->status,
            ],
        ],
    ], 201);
}
    // Student authentication: SDD one-device flow. Teacher auth methods above are kept intact.
    public function studentLogin(Request $request)
    {
        $validated = $request->validate(array_merge([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], $this->deviceValidationRules()));

        [$user, $student] = $this->validatedStudentCredentials(
            $validated['email'],
            $validated['password']
        );

        $result = DB::transaction(function () use ($request, $validated, $user, $student) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();

            $device = $this->findOrCreateDevice($validated);

            $activeStudentDevice = StudentDevice::where('student_id', $student->id)
                ->where('status', 'active')
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($activeStudentDevice && $activeStudentDevice->device_id !== $device->id) {
                throw new HttpResponseException(
                    response()->json([
                        'status' => false,
                        'message' => 'Your account is active on another device. Device transfer is required.',
                        'code' => 'DEVICE_TRANSFER_REQUIRED',
                    ], 409)
                );
            }

            $studentDevice = StudentDevice::where('student_id', $student->id)
                ->where('device_id', $device->id)
                ->lockForUpdate()
                ->first();

            if (! $studentDevice) {
                $studentDevice = StudentDevice::create([
                    'student_id' => $student->id,
                    'device_id' => $device->id,
                    'status' => 'active',
                    'is_active' => true,
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                    'revoke_reason' => null,
                ]);

                $this->recordDeviceEvent($studentDevice, 'device_registered', $request, [
                    'source' => 'login',
                ]);
            }

            if ($studentDevice->status !== 'active' || ! $studentDevice->is_active) {
                throw new HttpResponseException(
                    response()->json([
                        'status' => false,
                        'message' => 'This device is not active for this student. Device transfer is required.',
                        'code' => 'DEVICE_TRANSFER_REQUIRED',
                    ], 409)
                );
            }

            $studentDevice->update([
                'last_seen_at' => now(),
            ]);

            $sessionData = $this->issueStudentSession(
                $user,
                $studentDevice,
                $request,
                'login_success'
            );

            return array_merge($sessionData, [
                'device' => $device,
                'student_device' => $studentDevice,
            ]);
        });

        $student->load('school.region');

        return response()->json($this->studentAuthResponse(
            'Student logged in successfully.',
            $user,
            $student,
            $result
        ));
    }

    /**
     * Logout only ends the current authenticated session.
     * It MUST NOT revoke or delete the student's active device binding.
     * Therefore the student can log in again from the same device, while a
     * different device still requires an explicit transfer.
     */
    public function studentLogout(Request $request)
    {
        $user = $request->user();

        if (! $user || $user->role !== 'student') {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized student account.',
            ], 403);
        }

        DB::transaction(function () use ($request, $user) {
            $student = Student::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($student) {
                $studentDevice = StudentDevice::where('student_id', $student->id)
                    ->where('status', 'active')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if ($studentDevice) {
                    DeviceSession::where('student_device_id', $studentDevice->id)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'revoked',
                            'last_activity_at' => now(),
                            'revoked_at' => now(),
                            'revoke_reason' => 'logout',
                        ]);

                    $studentDevice->update([
                        'last_seen_at' => now(),
                    ]);

                    $this->recordDeviceEvent($studentDevice, 'logout', $request);
                }
            }

            $currentAccessToken = $user->currentAccessToken();

            if ($currentAccessToken && method_exists($currentAccessToken, 'delete')) {
                $currentAccessToken->delete();
            }
        });

        return response()->json([
            'status' => true,
            'message' => 'Logged out successfully. The registered student device remains active.',
        ]);
    }

    /**
     * Rotate a refresh token and issue a new access token.
     * This endpoint does not require a live access token because its purpose is
     * to recover from access-token expiry. The refresh token + active device
     * binding are the authentication factors here.
     */
    public function studentRefresh(Request $request)
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
            'device_uuid' => ['required', 'string', 'max:255'],
        ]);

        $refreshHash = hash('sha256', $validated['refresh_token']);

        $result = DB::transaction(function () use ($request, $validated, $refreshHash) {
            $deviceSession = DeviceSession::with([
                'studentDevice.device',
                'studentDevice.student.user',
            ])
                ->where('refresh_token_hash', $refreshHash)
                ->lockForUpdate()
                ->first();

            if (! $deviceSession) {
                return $this->refreshError(
                    401,
                    'Invalid refresh token.',
                    'INVALID_REFRESH_TOKEN'
                );
            }

            if ($deviceSession->status !== 'active') {
                return $this->refreshError(
                    401,
                    'This refresh session is no longer active.',
                    'REFRESH_SESSION_REVOKED'
                );
            }

            if ($deviceSession->expires_at->isPast()) {
                $deviceSession->update([
                    'status' => 'expired',
                    'revoked_at' => now(),
                    'revoke_reason' => 'expired',
                ]);

                return $this->refreshError(
                    401,
                    'Refresh token expired. Please log in again.',
                    'REFRESH_TOKEN_EXPIRED'
                );
            }

            $studentDevice = $deviceSession->studentDevice;
            $device = $studentDevice?->device;
            $student = $studentDevice?->student;
            $user = $student?->user;

            if (! $studentDevice || ! $device || ! $student || ! $user) {
                $deviceSession->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoke_reason' => 'invalid_device_session',
                ]);

                return $this->refreshError(
                    401,
                    'Invalid device session.',
                    'INVALID_DEVICE_SESSION'
                );
            }

            if ($user->role !== 'student' || $user->status !== 'active' || $student->status !== 'active') {
                $deviceSession->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoke_reason' => 'account_inactive',
                ]);

                return $this->refreshError(
                    403,
                    'Student account is not active.',
                    'ACCOUNT_INACTIVE'
                );
            }

            if ($device->status === 'blocked') {
                $deviceSession->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoke_reason' => 'security_flag',
                ]);

                return $this->refreshError(
                    403,
                    'This device is blocked.',
                    'DEVICE_BLOCKED'
                );
            }

            if ($device->device_uuid !== $validated['device_uuid']) {
                return $this->refreshError(
                    401,
                    'Refresh token does not belong to this device.',
                    'DEVICE_MISMATCH'
                );
            }

            if ($studentDevice->status !== 'active' || ! $studentDevice->is_active) {
                $deviceSession->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoke_reason' => 'device_revoked',
                ]);

                return $this->refreshError(
                    401,
                    'This student device is no longer active.',
                    'DEVICE_REVOKED'
                );
            }

            // Rotate the refresh token. Keep the original expiry so repeated
            // refresh calls cannot extend one device session forever.
            $newRefreshToken = Str::random(80);

            $deviceSession->update([
                'refresh_token_hash' => hash('sha256', $newRefreshToken),
                'last_activity_at' => now(),
            ]);

            $studentDevice->update([
                'last_seen_at' => now(),
            ]);

            $user->tokens()->delete();

            $accessTokenExpiresAt = now()->addMinutes($this->studentAccessTokenMinutes());
            $newAccessToken = $user->createToken(
                'student_device_'.$studentDevice->id.'_session_'.$deviceSession->id,
                ['student'],
                $accessTokenExpiresAt
            );

            $this->recordDeviceEvent($studentDevice, 'token_refresh', $request, [
                'device_session_id' => $deviceSession->id,
            ]);

            return [
                'ok' => true,
                'access_token' => $newAccessToken->plainTextToken,
                'access_token_expires_at' => $accessTokenExpiresAt->toDateTimeString(),
                'refresh_token' => $newRefreshToken,
                'refresh_token_expires_at' => $deviceSession->expires_at->toDateTimeString(),
            ];
        });

        if (! ($result['ok'] ?? false)) {
            return response()->json($result['payload'], $result['http_status']);
        }

        unset($result['ok']);

        return response()->json([
            'status' => true,
            'message' => 'Token refreshed successfully.',
            'token_type' => 'Bearer',
            ...$result,
        ]);
    }

    /**
     * Move the student account to a new physical device.
     *
     * UX-wise this is the continuation of login after
     * DEVICE_TRANSFER_REQUIRED. Technically it is kept as the dedicated
     * /auth/device/transfer endpoint required by the SDD.
     */
    public function studentDeviceTransfer(Request $request)
    {
        $validated = $request->validate(array_merge([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], $this->deviceValidationRules()));

        [$user, $student] = $this->validatedStudentCredentials(
            $validated['email'],
            $validated['password']
        );

        $result = DB::transaction(function () use ($request, $validated, $user, $student) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $newDevice = $this->findOrCreateDevice($validated);

            $activeStudentDevices = StudentDevice::where('student_id', $student->id)
                ->where('status', 'active')
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();

            foreach ($activeStudentDevices as $oldStudentDevice) {
                if ($oldStudentDevice->device_id === $newDevice->id) {
                    continue;
                }

                DeviceSession::where('student_device_id', $oldStudentDevice->id)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'revoked',
                        'last_activity_at' => now(),
                        'revoked_at' => now(),
                        'revoke_reason' => 'new_device_linked',
                    ]);

                $oldStudentDevice->update([
                    'status' => 'revoked',
                    'is_active' => false,
                    'last_seen_at' => now(),
                    'revoked_at' => now(),
                    'revoke_reason' => 'new_device_linked',
                ]);

                $this->recordDeviceEvent($oldStudentDevice, 'device_transfer_out', $request, [
                    'new_device_id' => $newDevice->id,
                    'reason' => 'new_device_linked',
                ]);
            }

            $newStudentDevice = StudentDevice::where('student_id', $student->id)
                ->where('device_id', $newDevice->id)
                ->lockForUpdate()
                ->first();

            if (! $newStudentDevice) {
                $newStudentDevice = StudentDevice::create([
                    'student_id' => $student->id,
                    'device_id' => $newDevice->id,
                    'status' => 'active',
                    'is_active' => true,
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                    'revoke_reason' => null,
                ]);
            } else {
                $newStudentDevice->update([
                    'status' => 'active',
                    'is_active' => true,
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                    'revoke_reason' => null,
                ]);
            }

            // Close any old sessions that may already exist for the target
            // device before issuing the new session.
            DeviceSession::where('student_device_id', $newStudentDevice->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'revoked',
                    'last_activity_at' => now(),
                    'revoked_at' => now(),
                    'revoke_reason' => 'new_device_linked',
                ]);

            $user->tokens()->delete();

            $this->recordDeviceEvent($newStudentDevice, 'device_transfer_in', $request, [
                'reason' => 'new_device_linked',
            ]);

            $sessionData = $this->issueStudentSession(
                $user,
                $newStudentDevice,
                $request,
                'login_success'
            );

            return array_merge($sessionData, [
                'device' => $newDevice,
                'student_device' => $newStudentDevice,
            ]);
        });

        $student->load('school.region');

        return response()->json($this->studentAuthResponse(
            'Device transfer completed and student logged in successfully.',
            $user,
            $student,
            $result
        ));
    }

    public function studentMe(Request $request)
    {
        $user = $request->user();

        if (! $user || $user->role !== 'student') {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized student account.',
            ], 403);
        }

        $student = Student::with('school.region')
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'status' => true,
            'user' => $this->userPayload($user),
            'student' => $student ? $this->studentPayload($student) : null,
        ]);
    }

    private function validatedStudentCredentials(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password_hash)) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'Invalid email or password.',
                ], 401)
            );
        }

        if ($user->role !== 'student') {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'This account is not a student account.',
                ], 403)
            );
        }

        if ($user->status !== 'active') {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'Your account is not active.',
                    'account_status' => $user->status,
                    'code' => 'ACCOUNT_INACTIVE',
                ], 403)
            );
        }

        $student = Student::where('user_id', $user->id)->first();

        if (! $student) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'Student profile not found.',
                ], 404)
            );
        }

        if ($student->status !== 'active') {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'Student profile is inactive.',
                    'code' => 'STUDENT_PROFILE_INACTIVE',
                ], 403)
            );
        }

        return [$user, $student];
    }

    private function deviceValidationRules(): array
    {
        return [
            'device_uuid' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:android,ios'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:255'],
            'public_key' => ['nullable', 'string'],
        ];
    }

    private function findOrCreateDevice(array $validated): Device
    {
        $device = Device::where('device_uuid', $validated['device_uuid'])
            ->lockForUpdate()
            ->first();

        if (! $device) {
            return Device::create([
                'device_uuid' => $validated['device_uuid'],
                'platform' => $validated['platform'],
                'manufacturer' => $validated['manufacturer'] ?? null,
                'model' => $validated['model'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'public_key' => $validated['public_key'] ?? null,
                'status' => 'trusted',
            ]);
        }

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

    private function issueStudentSession(
        User $user,
        StudentDevice $studentDevice,
        Request $request,
        string $eventType
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
        $refreshExpiresAt = now()->addDays($this->studentRefreshTokenDays());

        $deviceSession = DeviceSession::create([
            'student_device_id' => $studentDevice->id,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'status' => 'active',
            'last_activity_at' => now(),
            'expires_at' => $refreshExpiresAt,
            'revoked_at' => null,
            'revoke_reason' => null,
        ]);

        $accessTokenExpiresAt = now()->addMinutes($this->studentAccessTokenMinutes());

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

    private function studentAuthResponse(
        string $message,
        User $user,
        Student $student,
        array $result
    ): array {
        return [
            'status' => true,
            'message' => $message,
            'token_type' => 'Bearer',
            'access_token' => $result['access_token'],
            'access_token_expires_at' => $result['access_token_expires_at'],
            'refresh_token' => $result['refresh_token'],
            'refresh_token_expires_at' => $result['refresh_token_expires_at'],
            'user' => $this->userPayload($user),
            'student' => $this->studentPayload($student),
            'device' => [
                'device_uuid' => $result['device']->device_uuid,
                'platform' => $result['device']->platform,
                'manufacturer' => $result['device']->manufacturer,
                'model' => $result['device']->model,
                'os_version' => $result['device']->os_version,
                'app_version' => $result['device']->app_version,
                'status' => $result['device']->status,
            ],
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'region_id' => $user->region_id,
        ];
    }

    private function studentPayload(Student $student): array
    {
        return [
            'id' => $student->id,
            'grade' => $student->grade,
            'school_id' => $student->school_id,
            'status' => $student->status,
            'school' => $student->relationLoaded('school') ? $student->school : null,
        ];
    }

    private function refreshError(int $httpStatus, string $message, string $code): array
    {
        return [
            'ok' => false,
            'http_status' => $httpStatus,
            'payload' => [
                'status' => false,
                'message' => $message,
                'code' => $code,
            ],
        ];
    }

    private function studentAccessTokenMinutes(): int
    {
        return max(1, (int) config('student_auth.access_token_minutes', 15));
    }

    private function studentRefreshTokenDays(): int
    {
        return max(1, (int) config('student_auth.refresh_token_days', 30));
    }
}
