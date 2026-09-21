<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceEventController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = DeviceEvent::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $deviceEvent = DeviceEvent::create($request->validate([
            'student_device_id' => ['required', 'integer', 'exists:student_devices,id'],
            'event_type' => ['required', 'string', 'max:100'],
            'metadata_json' => ['nullable', 'array'],
            'created_at' => ['nullable', 'date'],
        ]));

        return $this->success($deviceEvent, 'Device Event created successfully.', 201);
    }

    public function show(DeviceEvent $deviceEvent): JsonResponse
    {
        return $this->success($deviceEvent);
    }

    public function update(Request $request, DeviceEvent $deviceEvent): JsonResponse
    {
        $deviceEvent->update($request->validate([
            'student_device_id' => ['sometimes', 'integer', 'exists:student_devices,id'],
            'event_type' => ['sometimes', 'string', 'max:100'],
            'metadata_json' => ['sometimes', 'nullable', 'array'],
            'created_at' => ['sometimes', 'nullable', 'date'],
        ]));

        return $this->success($deviceEvent->fresh(), 'Device Event updated successfully.');
    }

    public function destroy(DeviceEvent $deviceEvent): JsonResponse
    {
        $deviceEvent->delete();

        return $this->success(message: 'Device Event deleted successfully.');
    }
}

