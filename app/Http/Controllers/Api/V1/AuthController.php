<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
}
