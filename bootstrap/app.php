<?php

use App\Http\Middleware\ActiveStudentDevice;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Friend's teacher route file keeps its existing /api/v1/teacher prefix.
            Route::middleware('api')
                ->prefix('api/v1/teacher')
                ->group(base_path('routes/teacher.php'));

            // Student auth is kept in its own route file, but mounted at /api/v1
            // so SDD endpoints remain /api/v1/auth/... and /api/v1/me.
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/student.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [\App\Http\Middleware\LegacyTeacherSessions::class]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'student.device' => ActiveStudentDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/*') || $error instanceof ValidationException
                || $error instanceof HttpExceptionInterface
                || $error instanceof HttpResponseException
                || $error instanceof AuthenticationException
                || $error instanceof AuthorizationException
                || $error instanceof ModelNotFoundException) {
                return null;
            }

            return response()->json(['status' => false, 'code' => 'INTERNAL_ERROR', 'message' => 'تعذر إتمام العملية حالياً. يرجى المحاولة لاحقاً أو التواصل مع الدعم.'], 500);
        });
    })->create();
