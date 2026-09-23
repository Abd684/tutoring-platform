<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use App\Http\Controllers\Api\V1\TeacherSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [TeacherAuthController::class, 'registerTeacher']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/refresh', [AuthController::class, 'refresh']);

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/subscription-plans', [SubscriptionPlanController::class, 'available']);
    Route::post('/subscription-plans/{subscriptionPlan}/subscribe', [TeacherSubscriptionController::class, 'subscribe']);
});

Route::patch('/{teacher}/activate', [TeacherAuthController::class, 'activateTeacher'])
    ->middleware(['auth:sanctum', 'role:company_admin']);
