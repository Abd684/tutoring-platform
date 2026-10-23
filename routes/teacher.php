<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupEnrollmentController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\StudentLearningController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use App\Http\Controllers\Api\V1\TeacherAvailabilityController;
use App\Http\Controllers\Api\V1\TeacherContentAssetController;
use App\Http\Controllers\Api\V1\TeacherContentController;
use App\Http\Controllers\Api\V1\TeacherCourseController;
use App\Http\Controllers\Api\V1\TeacherDashboardController;
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
    Route::get('/content-assets', [TeacherContentAssetController::class, 'index']);
    Route::post('/contents', [TeacherContentController::class, 'store']);
    Route::post('/contents/{content}/asset', [TeacherContentAssetController::class, 'store']);
    Route::get('/content-assets/{contentAsset}', [TeacherContentAssetController::class, 'show']);
    Route::delete('/content-assets/{contentAsset}', [TeacherContentAssetController::class, 'destroy']);
    Route::get('/availability', [TeacherAvailabilityController::class, 'index']);
    Route::post('/availability', [TeacherAvailabilityController::class, 'store']);
    Route::put('/availability/{teacherAvailability}', [TeacherAvailabilityController::class, 'update']);
    Route::delete('/availability/{teacherAvailability}', [TeacherAvailabilityController::class, 'destroy']);
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::post('/sessions', [SessionController::class, 'store']);
    Route::patch('/sessions/{session}', [SessionController::class, 'update']);
    Route::post('v1/sessions/{session}/cancel', [SessionController::class, 'cancel']);
    Route::post('/sessions/{id}/attendance', [AttendanceController::class, 'recordForSession']);
});

Route::patch('/{teacher}/activate', [TeacherAuthController::class, 'activateTeacher'])
    ->middleware(['auth:sanctum', 'role:company_admin']);

// SDD teacher group APIs - teacher identity is derived from Bearer token.
Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::post('/groups', [GroupController::class, 'storeForTeacher']);
    Route::post('/groups/{id}/enrollments', [GroupEnrollmentController::class, 'addToTeacherGroup']);
    Route::delete('/groups/{id}/enrollments/{enrollment}', [GroupEnrollmentController::class, 'removeFromTeacherGroup']);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::patch('/profile', [StudentLearningController::class, 'updateTeacher']);
    Route::patch('/contents/{content}', [TeacherContentController::class, 'update']);
    Route::post('/sessions/{session}/complete', [SessionController::class, 'complete']);
    Route::post('/sessions/{session}/cancel', [SessionController::class, 'cancel']);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::get('/subject-catalog', [TeacherCourseController::class, 'catalog']);
    Route::post('/subjects', [TeacherCourseController::class, 'store']);
    Route::get('/subscription', [TeacherDashboardController::class, 'current']);
    Route::get('/subscriptions', [TeacherDashboardController::class, 'history']);
    Route::get('/usage', [TeacherDashboardController::class, 'usage']);
});
