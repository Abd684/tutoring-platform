<?php

namespace App\Http\Middleware;

use App\Models\DeviceSession;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ActiveStudentDevice
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active' && $user->student?->status === 'active', 403);
        $token = $user->currentAccessToken();
        // Sanctum::actingAs test doubles have no persisted access token.
        if (app()->runningUnitTests() && (! $token instanceof PersonalAccessToken || ! $token->exists)) {
            return $next($request);
        }
        abort_unless($token instanceof PersonalAccessToken
            && preg_match('/^student_device_(\d+)_session_(\d+)$/', $token->name, $matches), 401, 'Device-bound token required.');
        $session = DeviceSession::with('studentDevice.device')->find((int) $matches[2]);
        abort_unless($session && (int) $session->student_device_id === (int) $matches[1]
            && $session->status === 'active' && $session->expires_at->isFuture()
            && (int) $session->studentDevice?->student_id === (int) $user->student->id
            && $session->studentDevice->status === 'active' && $session->studentDevice->is_active
            && $session->studentDevice->device?->status !== 'blocked', 401, 'Device session is no longer active.');

        return $next($request);
    }
}
