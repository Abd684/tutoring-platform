<?php

use App\Http\Controllers\Api\V1\StudentAuthController;
use Illuminate\Support\Facades\Route;

// Student authentication routes.
// bootstrap/app.php mounts this file under /api/v1, therefore the public URLs
// remain /api/v1/auth/... exactly as required by the SDD.
Route::prefix('auth')->group(function (): void {
    Route::post('/register', [StudentAuthController::class, 'register']);
    Route::post('/login', [StudentAuthController::class, 'studentLogin']);
    Route::post('/refresh', [StudentAuthController::class, 'studentRefresh']);
    Route::post('/device/transfer', [StudentAuthController::class, 'studentDeviceTransfer']);

    Route::post('/logout', [StudentAuthController::class, 'studentLogout'])
        ->middleware(['auth:sanctum', 'role:student']);
});

Route::get('/me', [StudentAuthController::class, 'studentMe'])
    ->middleware(['auth:sanctum', 'role:student']);
