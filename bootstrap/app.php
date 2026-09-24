<?php

use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

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
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
