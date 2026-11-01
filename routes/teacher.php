<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupStudentController;
use App\Http\Controllers\Api\V1\IndividualController;
use App\Http\Controllers\Api\V1\IndividualOfferController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\StudentLearningController;
use App\Http\Controllers\Api\V1\StudentNotificationController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use App\Http\Controllers\Api\V1\TeacherAvailabilityController;
use App\Http\Controllers\Api\V1\TeacherContentAssetController;
use App\Http\Controllers\Api\V1\TeacherContentController;
use App\Http\Controllers\Api\V1\TeacherCourseController;
use App\Http\Controllers\Api\V1\TeacherDashboardController;
use App\Http\Controllers\Api\V1\TeacherGroupMembersController;
use App\Http\Controllers\Api\V1\TeacherSubjectManagementController;
use App\Http\Controllers\Api\V1\TeacherSubscriptionController;
use App\Http\Controllers\Api\V1\TeachingContentController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [TeacherAuthController::class, 'registerTeacher']);

Route::post('/content-assets/{contentAsset}/hls/retry', [TeacherContentAssetController::class, 'retry'])
    ->middleware(['auth:sanctum', 'role:teacher']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/refresh', [AuthController::class, 'refresh']);

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::apiResource('subjects', TeacherSubjectManagementController::class)
        ->names('teacher.subjects')
        ->parameters(['subjects' => 'teacherSubject']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/subject-catalog', [TeacherCourseController::class, 'catalog']);
    Route::get('/subscription', [TeacherDashboardController::class, 'current']);
    Route::get('/subscriptions', [TeacherDashboardController::class, 'history']);
    Route::get('/usage', [TeacherDashboardController::class, 'usage']);
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
    Route::get('/groups', [GroupController::class, 'indexForTeacher']);
    Route::post('/groups', [GroupController::class, 'storeForTeacher']);
    Route::post('/groups/{id}/students', [GroupStudentController::class, 'addToTeacherGroup']);
    Route::delete('/groups/{id}/students/{student}', [GroupStudentController::class, 'removeFromTeacherGroup']);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::patch('/profile', [StudentLearningController::class, 'updateTeacher']);
    Route::patch('/contents/{content}', [TeacherContentController::class, 'update']);
    Route::post('/sessions/{session}/complete', [SessionController::class, 'complete']);
    Route::post('/sessions/{session}/cancel', [SessionController::class, 'cancel']);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::patch('/sessions/{session}/progress', [SessionController::class, 'progress']);
    Route::apiResource('individuals', IndividualController::class)->except('destroy');
});

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function (): void {
    Route::get('/individual-offers', [IndividualOfferController::class, 'index']);
    Route::post('/individual-offers', [IndividualOfferController::class, 'store']);
    Route::patch('/individual-offers/{individualOffer}', [IndividualOfferController::class, 'update']);
    Route::get('/groups/{group}/students', [TeacherGroupMembersController::class, 'index']);
    Route::post('/units', [TeachingContentController::class, 'storeUnit']);
    Route::patch('/units/{unit}', [TeachingContentController::class, 'updateUnit']);
    Route::post('/lessons', [TeachingContentController::class, 'storeLesson']);
    Route::patch('/lessons/{lesson}', [TeachingContentController::class, 'updateLesson']);
    Route::put('/contents/{content}/quiz', [TeachingContentController::class, 'quiz']);
});

Route::middleware(['auth:sanctum', 'role:teacher'])->group(function () {
    Route::get('/notifications', [StudentNotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [StudentNotificationController::class, 'read']);
});

Route::post('/answers/{answer}/grade', [TeachingContentController::class, 'grade'])->middleware(['auth:sanctum', 'role:teacher']);
