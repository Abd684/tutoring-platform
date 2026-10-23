<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StudentDeviceWhenRequired
{
    public function handle(Request $request, Closure $next)
    {
        return $request->user()?->role === 'student' ? app(ActiveStudentDevice::class)->handle($request, $next) : $next($request);
    }
}
