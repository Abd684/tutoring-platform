<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DeviceEvent;
use App\Models\DeviceSession;
use App\Models\RefreshToken;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Existing generic admin endpoint.
     * Teacher/non-student behavior stays exactly the same. Student targets are
     * delegated to the stricter student suspension flow so device sessions and
     * refresh/access tokens cannot survive suspension.
     */
    public function suspendUser(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'student') {
            return $this->suspendStudent($request, $user);
        }

        $user->update(['status' => 'suspended']);

        return response()->json([
            'message' => 'User suspended successfully.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Existing generic admin endpoint.
     * Teacher/non-student behavior stays exactly the same.
     */
    public function activateUser(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'student') {
            return $this->activateStudent($request, $user);
        }

        $user->update(['status' => 'active']);

        return response()->json([
            'message' => 'User account activated successfully.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Suspend a student without deleting/revoking the registered physical
     * device binding. All live access and refresh sessions are invalidated.
     */
    public function suspendStudent(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($user->role !== 'student') {
            return response()->json([
                'status' => false,
                'message' => 'The selected user is not a student.',
            ], 422);
        }

        DB::transaction(function () use ($request, $validated, $user): void {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $student = Student::where('user_id', $lockedUser->id)->lockForUpdate()->first();

            $lockedUser->update(['status' => 'suspended']);

            // Revoke every Sanctum access token immediately.
            $lockedUser->tokens()->delete();

            // Revoke any generic refresh token left by an older/shared auth flow.
            RefreshToken::query()
                ->where('user_id', $lockedUser->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            if ($student) {
                $studentDevices = StudentDevice::where('student_id', $student->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($studentDevices as $studentDevice) {
                    DeviceSession::where('student_device_id', $studentDevice->id)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'revoked',
                            'last_activity_at' => now(),
                            'revoked_at' => now(),
                            'revoke_reason' => 'admin_action',
                        ]);

                    // Device binding deliberately remains active. Suspension
                    // must not become a way to bypass the one-device rule.
                    if ($studentDevice->status === 'active' && $studentDevice->is_active) {
                        DeviceEvent::create([
                            'student_device_id' => $studentDevice->id,
                            'event_type' => 'account_suspended',
                            'metadata_json' => [
                                'reason' => $validated['reason'] ?? null,
                                'admin_user_id' => $request->user()->id,
                                'ip' => $request->ip(),
                            ],
                            'created_at' => now(),
                        ]);
                    }
                }
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'student_account_suspended',
                'entity_type' => User::class,
                'entity_id' => $lockedUser->id,
                'metadata_json' => [
                    'reason' => $validated['reason'] ?? null,
                ],
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'status' => true,
            'message' => 'Student account suspended successfully.',
            'user_id' => $user->id,
            'account_status' => 'suspended',
        ]);
    }

    /**
     * Reactivate the student account. Device ownership is not changed and no
     * token is issued; the student must log in again from the registered device.
     */
    public function activateStudent(Request $request, User $user): JsonResponse
    {
        if ($user->role !== 'student') {
            return response()->json([
                'status' => false,
                'message' => 'The selected user is not a student.',
            ], 422);
        }

        DB::transaction(function () use ($request, $user): void {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $lockedUser->update(['status' => 'active']);

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'student_account_activated',
                'entity_type' => User::class,
                'entity_id' => $lockedUser->id,
                'metadata_json' => null,
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'status' => true,
            'message' => 'Student account activated successfully.',
            'user_id' => $user->id,
            'account_status' => 'active',
        ]);
    }
}
