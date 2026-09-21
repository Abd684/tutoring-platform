<?php

use App\Http\Controllers\Api\V1\AiEvaluationController;
use App\Http\Controllers\Api\V1\AnswerController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\ContentAssetController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DeviceEventController;
use App\Http\Controllers\Api\V1\DeviceSessionController;
use App\Http\Controllers\Api\V1\EscrowTransactionController;
use App\Http\Controllers\Api\V1\GovernortateController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupEnrollmentController;
use App\Http\Controllers\Api\V1\LessonController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PdfAssetController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\QuizAttemptController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\RegionController;
use App\Http\Controllers\Api\V1\SchoolController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\TeacherAvailabilityController;
use App\Http\Controllers\Api\V1\TeacherController;
use App\Http\Controllers\Api\V1\TeacherSubjectController;
use App\Http\Controllers\Api\V1\TeacherSubscriptionController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VideoAssetController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WalletTransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function () {
        Route::apiResources([
            'teachers' => TeacherController::class,
            'subjects' => SubjectController::class,
            'teacher-subjects' => TeacherSubjectController::class,
            'units' => UnitController::class,
            'lessons' => LessonController::class,
            'contents' => ContentController::class,
            'content-assets' => ContentAssetController::class,
            'groups' => GroupController::class,
            'group-enrollments' => GroupEnrollmentController::class,
            'payments' => PaymentController::class,
            'subscription-plans' => SubscriptionPlanController::class,
            'teacher-subscriptions' => TeacherSubscriptionController::class,
        ]);


        // Public authentication routes (temporary test routes in api.php)
        Route::prefix('auth')->group(function () {
            Route::post('/register', [AuthController::class, 'register']);
            Route::post('/login', [AuthController::class, 'studentLogin']);
        });
        Route::apiResource('users', UserController::class);
        Route::apiResource('students', StudentController::class);
        Route::apiResource('governortates', GovernortateController::class);
        Route::apiResource('regions', RegionController::class);
        Route::apiResource('schools', SchoolController::class);
        Route::apiResource('teacher-availabilities', TeacherAvailabilityController::class);
        Route::apiResource('sessions', SessionController::class);
        Route::apiResource('attendance', AttendanceController::class);
        Route::apiResource('video-assets', VideoAssetController::class);
        Route::apiResource('pdf-assets', PdfAssetController::class);
        Route::apiResource('devices', DeviceController::class);
        Route::apiResource('device-sessions', DeviceSessionController::class);
        Route::apiResource('device-events', DeviceEventController::class);
        Route::apiResource('wallets', WalletController::class);
        Route::apiResource('wallet-transactions', WalletTransactionController::class);
        Route::apiResource('quizzes', QuizController::class);
        Route::apiResource('questions', QuestionController::class);
        Route::apiResource('quiz-attempts', QuizAttemptController::class);
        Route::apiResource('answers', AnswerController::class);
        Route::apiResource('ai-evaluations', AiEvaluationController::class);
        Route::apiResource('notifications', NotificationController::class);
        Route::apiResource('audit-logs', AuditLogController::class);
        Route::apiResource('escrow-transactions', EscrowTransactionController::class);
    });

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/admin-only', function () {
        return response()->json(['message' => 'Welcome, admin!']);
    });
});
