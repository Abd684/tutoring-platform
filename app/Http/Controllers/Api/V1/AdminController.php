<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    public function suspendUser(User $user): JsonResponse
    {
        $user->update(['status' => 'suspended']);

        return response()->json([
            'message' => 'User suspended successfully.',
            'user' => $user->fresh(),
        ]);
    }

    public function activateUser(User $user): JsonResponse
    {
        $user->update(['status' => 'active']);

        return response()->json([
            'message' => 'User account activated successfully.',
            'user' => $user->fresh(),
        ]);
    }
}
