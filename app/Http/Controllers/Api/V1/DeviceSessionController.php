<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceSessionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = DeviceSession::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $deviceSession = DeviceSession::create($request->validate([
            'student_device_id' => ['required', 'integer', 'exists:student_devices,id'],
            'refresh_token_hash' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:50'],
            'last_activity_at' => ['nullable', 'date'],
            'expires_at' => ['required', 'date'],
            'revoked_at' => ['nullable', 'date'],
            'revoke_reason' => ['nullable', 'string', 'max:255'],
        ]));

        return $this->success($deviceSession, 'Device Session created successfully.', 201);
    }

    public function show(DeviceSession $deviceSession): JsonResponse
    {
        return $this->success($deviceSession);
    }

    public function update(Request $request, DeviceSession $deviceSession): JsonResponse
    {
        $deviceSession->update($request->validate([
            'student_device_id' => ['sometimes', 'integer', 'exists:student_devices,id'],
            'refresh_token_hash' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:50'],
            'last_activity_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'date'],
            'revoked_at' => ['sometimes', 'nullable', 'date'],
            'revoke_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]));

        return $this->success($deviceSession->fresh(), 'Device Session updated successfully.');
    }

    public function destroy(DeviceSession $deviceSession): JsonResponse
    {
        $deviceSession->delete();

        return $this->success(message: 'Device Session deleted successfully.');
    }
}

