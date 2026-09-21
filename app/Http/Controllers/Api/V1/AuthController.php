<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

use App\Models\User;
use App\Models\Student;
use App\Models\Device;
use App\Models\StudentDevice;
use App\Models\DeviceSession;
use App\Models\DeviceEvent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\Exceptions\HttpResponseException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (auth()->attempt($credentials)) {
            $user = auth()->user();
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);
        }

        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
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
//تسجيل دخول الطالب
public function studentLogin(Request $request)
{
    $validated = $request->validate([
        'email' => [
            'required',
            'email',
        ],

        'password' => [
            'required',
            'string',
        ],

        'device_uuid' => [
            'required',
            'string',
            'max:255',
        ],

        'platform' => [
            'required',
            'in:android,ios',
        ],

        'manufacturer' => [
            'nullable',
            'string',
            'max:255',
        ],

        'model' => [
            'nullable',
            'string',
            'max:255',
        ],

        'os_version' => [
            'nullable',
            'string',
            'max:255',
        ],

        'app_version' => [
            'nullable',
            'string',
            'max:255',
        ],

        'public_key' => [
            'nullable',
            'string',
        ],
    ]);

    /*
    |--------------------------------------------------------------------------
    | 1. Check email + password
    |--------------------------------------------------------------------------
    */

    $user = User::where('email', $validated['email'])->first();

    if (! $user || ! Hash::check($validated['password'], $user->password_hash)) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid email or password.',
        ], 401);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Student only
    |--------------------------------------------------------------------------
    */

    if ($user->role !== 'student') {
        return response()->json([
            'status' => false,
            'message' => 'This account is not a student account.',
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Check account status
    |--------------------------------------------------------------------------
    */

    if ($user->status !== 'active') {
        return response()->json([
            'status' => false,
            'message' => 'Your account is not active.',
            'account_status' => $user->status,
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Get Student profile
    |--------------------------------------------------------------------------
    */

    $student = Student::where('user_id', $user->id)->first();

    if (! $student) {
        return response()->json([
            'status' => false,
            'message' => 'Student profile not found.',
        ], 404);
    }

    if ($student->status !== 'active') {
        return response()->json([
            'status' => false,
            'message' => 'Student profile is inactive.',
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Login + Device transaction
    |--------------------------------------------------------------------------
    */

    $result = DB::transaction(function () use (
        $request,
        $validated,
        $user,
        $student
    ) {

        /*
        |--------------------------------------------------------------------------
        | Lock student while checking active device
        |--------------------------------------------------------------------------
        */

        Student::whereKey($student->id)
            ->lockForUpdate()
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Find/Create physical Device
        |--------------------------------------------------------------------------
        */

        $device = Device::where(
            'device_uuid',
            $validated['device_uuid']
        )->first();

        if (! $device) {
            $device = Device::create([
                'device_uuid' => $validated['device_uuid'],
                'platform' => $validated['platform'],
                'manufacturer' => $validated['manufacturer'] ?? null,
                'model' => $validated['model'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'public_key' => $validated['public_key'] ?? null,
                'status' => 'trusted',
            ]);
        } else {

            /*
            |--------------------------------------------------------------------------
            | Blocked physical device
            |--------------------------------------------------------------------------
            */

            if ($device->status === 'blocked') {
                throw new HttpResponseException(
                    response()->json([
                        'status' => false,
                        'message' => 'This device is blocked.',
                        'code' => 'DEVICE_BLOCKED',
                    ], 403)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Refresh device information
            |--------------------------------------------------------------------------
            */

            $device->update([
                'platform' => $validated['platform'],
                'manufacturer' => $validated['manufacturer'] ?? $device->manufacturer,
                'model' => $validated['model'] ?? $device->model,
                'os_version' => $validated['os_version'] ?? $device->os_version,
                'app_version' => $validated['app_version'] ?? $device->app_version,
                'public_key' => $validated['public_key'] ?? $device->public_key,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 6. Check currently active StudentDevice
        |--------------------------------------------------------------------------
        */

        $activeStudentDevice = StudentDevice::where(
            'student_id',
            $student->id
        )
            ->where('status', 'active')
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Student already owns another active device
        |--------------------------------------------------------------------------
        */

        if (
            $activeStudentDevice &&
            $activeStudentDevice->device_id !== $device->id
        ) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'Your account is active on another device. Device transfer is required.',
                    'code' => 'DEVICE_TRANSFER_REQUIRED',
                ], 409)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Find existing relation between student + current device
        |--------------------------------------------------------------------------
        */

        $studentDevice = StudentDevice::where(
            'student_id',
            $student->id
        )
            ->where('device_id', $device->id)
            ->lockForUpdate()
            ->first();

        /*
        |--------------------------------------------------------------------------
        | First device for student
        |--------------------------------------------------------------------------
        */

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

            DeviceEvent::create([
                'student_device_id' => $studentDevice->id,
                'event_type' => 'device_registered',
                'metadata_json' => [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
                'created_at' => now(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Device relation was revoked before
        |--------------------------------------------------------------------------
        |
        | Login is NOT allowed to silently restore it.
        | Device Transfer API will handle that.
        |--------------------------------------------------------------------------
        */

        if (
            $studentDevice->status === 'revoked' ||
            ! $studentDevice->is_active
        ) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => 'This device is not active for this student. Device transfer is required.',
                    'code' => 'DEVICE_TRANSFER_REQUIRED',
                ], 409)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Update last seen
        |--------------------------------------------------------------------------
        */

        $studentDevice->update([
            'last_seen_at' => now(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 8. Revoke old sessions on SAME device
        |--------------------------------------------------------------------------
        */

        DeviceSession::where(
            'student_device_id',
            $studentDevice->id
        )
            ->where('status', 'active')
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoke_reason' => 'new_login',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Remove previous Sanctum tokens for this student
        |--------------------------------------------------------------------------
        |
        | Because student has only one active login/device.
        |--------------------------------------------------------------------------
        */

        $user->tokens()->delete();

        /*
        |--------------------------------------------------------------------------
        | 9. Generate Refresh Token
        |--------------------------------------------------------------------------
        */

        $refreshToken = Str::random(80);

        $refreshExpiresAt = now()->addDays(30);

        $deviceSession = DeviceSession::create([
            'student_device_id' => $studentDevice->id,

            // Never save raw refresh token
            'refresh_token_hash' => hash(
                'sha256',
                $refreshToken
            ),

            'status' => 'active',

            'last_activity_at' => now(),

            'expires_at' => $refreshExpiresAt,

            'revoked_at' => null,

            'revoke_reason' => null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 10. Generate Sanctum Access Token
        |--------------------------------------------------------------------------
        */

        $accessTokenExpiresAt = now()->addHours(12);

        $newToken = $user->createToken(
            'student_device_'.$studentDevice->id,
            ['student'],
            $accessTokenExpiresAt
        );

        /*
        |--------------------------------------------------------------------------
        | 11. Record successful login event
        |--------------------------------------------------------------------------
        */

        DeviceEvent::create([
            'student_device_id' => $studentDevice->id,
            'event_type' => 'login_success',
            'metadata_json' => [
                'device_session_id' => $deviceSession->id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
            'created_at' => now(),
        ]);

        return [
            'access_token' => $newToken->plainTextToken,

            'access_token_expires_at' =>
                $accessTokenExpiresAt->toDateTimeString(),

            'refresh_token' => $refreshToken,

            'refresh_token_expires_at' =>
                $refreshExpiresAt->toDateTimeString(),

            'device' => $device,

            'student_device' => $studentDevice,

            'device_session' => $deviceSession,
        ];
    });

    /*
    |--------------------------------------------------------------------------
    | 12. Final Response
    |--------------------------------------------------------------------------
    */

    $student->load([
        'school.region',
    ]);

    return response()->json([
        'status' => true,

        'message' => 'Student logged in successfully.',

        'token_type' => 'Bearer',

        'access_token' => $result['access_token'],

        'access_token_expires_at' =>
            $result['access_token_expires_at'],

        'refresh_token' => $result['refresh_token'],

        'refresh_token_expires_at' =>
            $result['refresh_token_expires_at'],

        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'region_id' => $user->region_id,
        ],

        'student' => [
            'id' => $student->id,
            'grade' => $student->grade,
            'school_id' => $student->school_id,
            'status' => $student->status,
            'school' => $student->school,
        ],

        'device' => [
            'device_uuid' => $result['device']->device_uuid,
            'platform' => $result['device']->platform,
            'manufacturer' => $result['device']->manufacturer,
            'model' => $result['device']->model,
            'os_version' => $result['device']->os_version,
            'app_version' => $result['device']->app_version,
            'status' => $result['device']->status,
        ],
    ], 200);
}

}
