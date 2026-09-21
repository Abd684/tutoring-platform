<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Device::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $device = Device::create($request->validate([
            'device_uuid' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:50'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'os_version' => ['nullable', 'string', 'max:50'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'public_key' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($device, 'Device created successfully.', 201);
    }

    public function show(Device $device): JsonResponse
    {
        return $this->success($device);
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $device->update($request->validate([
            'device_uuid' => ['sometimes', 'string', 'max:255'],
            'platform' => ['sometimes', 'string', 'max:50'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'public_key' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($device->fresh(), 'Device updated successfully.');
    }

    public function destroy(Device $device): JsonResponse
    {
        $device->delete();

        return $this->success(message: 'Device deleted successfully.');
    }
}

