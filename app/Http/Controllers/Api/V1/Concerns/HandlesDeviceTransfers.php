<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\DeviceTransferRequest;
use App\Models\Notification;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Services\DeviceTransfers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

trait HandlesDeviceTransfers
{
    public function studentDeviceTransfer(Request $request)
    {
        $validated = $request->validate(array_merge([
            'email' => ['required', 'email'], 'password' => ['required', 'string'],
        ], $this->deviceValidationRules()));
        [$user, $student] = $this->validatedStudentCredentials($validated['email'], $validated['password']);

        return DB::transaction(function () use ($validated, $student, $user) {
            $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $target = $this->findOrCreateDevice($validated);
            $source = StudentDevice::where('student_id', $student->id)->where('status', 'active')->where('is_active', true)->first();
            abort_unless($source && (int) $source->device_id !== (int) $target->id, 409, 'Transfer requires a different active device.');
            abort_if(DeviceTransferRequest::where('student_id', $student->id)->where('status', 'pending')->exists(), 409, 'A transfer request is already pending.');
            $secret = Str::random(80);
            $transfer = DeviceTransferRequest::create([
                'student_id' => $student->id, 'source_student_device_id' => $source->id,
                'target_device_id' => $target->id, 'token_hash' => hash('sha256', $secret),
                'expires_at' => now()->addHours(72), 'status' => 'pending',
            ]);
            Notification::create(['user_id' => $user->id, 'type' => 'device_transfer_requested',
                'title' => 'Device transfer approval required', 'body' => json_encode([
                    'transfer_request_id' => $transfer->id, 'target_device' => $target->only(['platform', 'manufacturer', 'model']),
                    'expires_at' => $transfer->expires_at->toIso8601String(),
                ])]);

            return response()->json(['status' => true, 'data' => [
                'id' => $transfer->id, 'status' => 'pending', 'expires_at' => $transfer->expires_at->toIso8601String(),
                'transfer_token' => $secret,
            ]], 202);
        });
    }

    public function transferStatus(Request $request, DeviceTransferRequest $transfer)
    {
        $this->checkTransferSecret($request, $transfer);
        $transfer = app(DeviceTransfers::class)->resolve($transfer->id);

        return response()->json(['status' => true, 'data' => $transfer->only(['id', 'status', 'expires_at', 'resolved_at', 'claimed_at'])]);
    }

    private function checkTransferSecret(Request $request, DeviceTransferRequest $transfer): void
    {
        $secret = (string) $request->header('X-Transfer-Token', '');
        abort_unless($secret !== '' && hash_equals($transfer->token_hash, hash('sha256', $secret)), 404);
    }

    public function claimTransfer(Request $request, DeviceTransferRequest $transfer)
    {
        $this->checkTransferSecret($request, $transfer);
        app(DeviceTransfers::class)->resolve($transfer->id);

        return DB::transaction(function () use ($request, $transfer) {
            $student = Student::whereKey($transfer->student_id)->lockForUpdate()->firstOrFail();
            $transfer = $transfer->fresh();
            abort_unless(in_array($transfer->status, ['approved', 'auto_approved'], true) && ! $transfer->claimed_at, 409, 'Transfer is not claimable.');
            abort_if($transfer->resolved_at->copy()->addHours(72)->isPast(), 410, 'Transfer claim expired; log in on the new device.');
            $user = $student->user;
            abort_unless($student->status === 'active' && $user->status === 'active', 403);
            $binding = StudentDevice::where('student_id', $student->id)->where('device_id', $transfer->target_device_id)
                ->where('status', 'active')->where('is_active', true)->firstOrFail();
            $device = $binding->device;
            abort_if($device->status === 'blocked', 403);
            $result = $this->issueStudentSession($user, $binding, $request, 'login_success');
            $transfer->update(['claimed_at' => now()]);
            $student->load('school.region');

            return response()->json($this->studentAuthResponse('Device transfer completed.', $user, $student,
                array_merge($result, ['device' => $device, 'student_device' => $binding])));
        });
    }

    public function pendingTransfers(Request $request)
    {
        $bindingId = $this->authenticatedBindingId($request);
        $requests = DeviceTransferRequest::where('student_id', $request->user()->student->id)
            ->where('source_student_device_id', $bindingId)->where('status', 'pending')->orderBy('id')->get();

        return response()->json(['status' => true, 'data' => $requests]);
    }

    public function decideTransfer(Request $request, DeviceTransferRequest $transfer)
    {
        $this->authorize('decide', $transfer);
        $resolved = app(DeviceTransfers::class)->resolve($transfer->id, $request->route('decision'));

        return response()->json(['status' => true, 'data' => $resolved->only(['id', 'status', 'expires_at', 'resolved_at'])]);
    }

    private function authenticatedBindingId(Request $request): int
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken
            && preg_match('/^student_device_(\d+)_session_\d+$/', $token->name, $matches), 403, 'A device-bound access token is required.');

        return (int) $matches[1];
    }
}
