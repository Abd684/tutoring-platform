<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! $request->user()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! in_array($request->user()->role, $roles, true)) {
            return response()->json(['message' => 'you do not have permission to access this resource'], 403);
        }

        if ($request->user()->role === 'teacher' && ($request->user()->status !== 'active' || $request->user()->teacher?->status !== 'active')) {
            $action = $request->route()?->getActionName();
            $allowed = [
                'App\Http\Controllers\Api\V1\AuthController@me',
                'App\Http\Controllers\Api\V1\AuthController@logout',
            ];
            if (! in_array($action, $allowed, true)) {
                return response()->json(['status' => false, 'code' => 'TEACHER_NOT_VERIFIED', 'message' => 'حسابك بانتظار التوثيق. يمكنك متابعة حالة حسابك، وتتاح خدمات التدريس بعد اعتماده.'], 403);
            }
        }

        return $next($request);
    }
}
