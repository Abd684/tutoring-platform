<?php

use App\Http\Controllers\Api\V1\StudentAuthController;
use App\Http\Controllers\Api\V1\StudentEnrollmentController;
use App\Http\Controllers\Api\V1\StudentContentAssetController;
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

// Student enrollment / purchase APIs from the SDD.
Route::middleware(['auth:sanctum', 'role:student'])->group(function (): void {
    Route::get('/student/enrollments', [StudentEnrollmentController::class, 'index']);
    Route::post('/teacher-subjects/{id}/enroll', [StudentEnrollmentController::class, 'enrollTeacherSubject']);
    Route::post('/units/{id}/enroll', [StudentEnrollmentController::class, 'enrollUnit']);
    Route::post('/contents/{id}/enroll', [StudentEnrollmentController::class, 'enrollContent']);
});

Route::post('/contents/{id}/playback-token', [StudentContentAssetController::class, 'playbackToken'])
    ->middleware(['auth:sanctum', 'role:student']);

Route::get('/content-assets/{contentAsset}/play', [StudentContentAssetController::class, 'play'])
    ->middleware(['auth:sanctum', 'role:student', 'signed'])
    ->name('student.content.play');
