<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $notification = Notification::create($request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'read_at' => ['nullable', 'date'],
        ]));

        return $this->success($notification, 'Notification created successfully.', 201);
    }

    public function show(Notification $notification): JsonResponse
    {
        return $this->success($notification);
    }

    public function update(Request $request, Notification $notification): JsonResponse
    {
        $notification->update($request->validate([
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'type' => ['sometimes', 'string', 'max:100'],
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'read_at' => ['sometimes', 'nullable', 'date'],
        ]));

        return $this->success($notification->fresh(), 'Notification updated successfully.');
    }

    public function destroy(Notification $notification): JsonResponse
    {
        $notification->delete();

        return $this->success(message: 'Notification deleted successfully.');
    }
}

