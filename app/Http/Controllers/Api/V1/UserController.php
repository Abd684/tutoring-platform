<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = User::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255'],
            'password_hash' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'max:50'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
        ]));

        return $this->success($user, 'User created successfully.', 201);
    }

    public function show(User $user): JsonResponse
    {
        return $this->success($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $user->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'email', 'max:255'],
            'password_hash' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'max:50'],
            'region_id' => ['sometimes', 'nullable', 'integer', 'exists:regions,id'],
        ]));

        return $this->success($user->fresh(), 'User updated successfully.');
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return $this->success(message: 'User deleted successfully.');
    }
}
