<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\CurriculumBrowseController;
use App\Http\Controllers\Api\V1\EnrollmentPaymentController;
use App\Http\Controllers\Api\V1\FeaturedTeacherController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProfileAvatarController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\ShamCashPaymentController;
use App\Http\Controllers\Api\V1\ShamCashSettingController;
use App\Http\Controllers\Api\V1\TeacherDirectoryController;
use App\Http\Controllers\Api\V1\TeacherSubjectController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Middleware\StudentDeviceWhenRequired;
use Illuminate\Support\Facades\Route;

// use App\Http\Controllers\Api\V1\AiEvaluationController;
// use App\Http\Controllers\Api\V1\AnswerController;
// use App\Http\Controllers\Api\V1\AttendanceController;
// use App\Http\Controllers\Api\V1\AuditLogController;
// use App\Http\Controllers\Api\V1\ContentController;
// use App\Http\Controllers\Api\V1\DeviceController;
// use App\Http\Controllers\Api\V1\DeviceEventController;
// use App\Http\Controllers\Api\V1\DeviceSessionController;
// use App\Http\Controllers\Api\V1\EscrowTransactionController;
// use App\Http\Controllers\Api\V1\GovernortateController;
// use App\Http\Controllers\Api\V1\GroupController;
// use App\Http\Controllers\Api\V1\GroupEnrollmentController;
// use App\Http\Controllers\Api\V1\LessonController;
// use App\Http\Controllers\Api\V1\NotificationController;
// use App\Http\Controllers\Api\V1\PdfAssetController;
// use App\Http\Controllers\Api\V1\QuestionController;
// use App\Http\Controllers\Api\V1\QuizAttemptController;
// use App\Http\Controllers\Api\V1\QuizController;
// use App\Http\Controllers\Api\V1\RegionController;
// use App\Http\Controllers\Api\V1\SchoolController;
// use App\Http\Controllers\Api\V1\SessionController;
// use App\Http\Controllers\Api\V1\StudentController;
// use App\Http\Controllers\Api\V1\SubjectController;
// use App\Http\Controllers\Api\V1\SubscriptionPlanController;
// use App\Http\Controllers\Api\V1\TeacherAvailabilityController;
// use App\Http\Controllers\Api\V1\TeacherController;
// use App\Http\Controllers\Api\V1\TeacherSubscriptionController;
// use App\Http\Controllers\Api\V1\UserController;
// use App\Http\Controllers\Api\V1\VideoAssetController;
// use App\Http\Controllers\Api\V1\WalletController;
// use App\Http\Controllers\Api\V1\WalletTransactionController;

Route::prefix('v1')->group(function (): void {
    Route::get('/teachers/featured', [FeaturedTeacherController::class, 'index']);
    Route::get('/teachers/directory', [TeacherDirectoryController::class, 'index']);
    Route::get('/teachers', [TeacherDirectoryController::class, 'index']);
    Route::get('/avatars/{user}/{filename}', [ProfileAvatarController::class, 'show'])
        ->where('filename', '[A-Za-z0-9_-]+\\.(jpg|jpeg|png|webp)')->name('profile.avatar.show');
    Route::get('/teacher-subjects/most-requested', [TeacherSubjectController::class, 'mostRequested']);
    Route::get('/teachers/{teacher}/subjects', [TeacherSubjectController::class, 'getSubjectsByTeacher']);
    Route::get('/teacher/subjects/{teacherSubject}/units', [TeacherSubjectController::class, 'units']);
    Route::get('/units/{unit}/lessons', [UnitController::class, 'lessons']);
    Route::get('/lessons/{lesson}/contents', [CurriculumBrowseController::class, 'lessonContents']);
});

// Route::apiResources([
//     'teachers' => TeacherController::class,
//     'subjects' => SubjectController::class,
//     'teacher-subjects' => TeacherSubjectController::class,
//     'units' => UnitController::class,
//     'lessons' => LessonController::class,
//     'contents' => ContentController::class,
//     'groups' => GroupController::class,
//     'group-enrollments' => GroupEnrollmentController::class,
//     'subscription-plans' => SubscriptionPlanController::class,
//     'teacher-subscriptions' => TeacherSubscriptionController::class,
// ]);

// Route::apiResource('users', UserController::class);
// Route::apiResource('students', StudentController::class);
// Route::apiResource('governortates', GovernortateController::class);
// Route::apiResource('regions', RegionController::class);
// Route::apiResource('schools', SchoolController::class);
// Route::apiResource('teacher-availabilities', TeacherAvailabilityController::class);
// Route::apiResource('attendance', AttendanceController::class);
// Route::apiResource('video-assets', VideoAssetController::class);
// Route::apiResource('pdf-assets', PdfAssetController::class);
// Route::apiResource('devices', DeviceController::class);
// Route::apiResource('device-sessions', DeviceSessionController::class);
// Route::apiResource('device-events', DeviceEventController::class);
// Route::apiResource('wallets', WalletController::class);
// Route::apiResource('wallet-transactions', WalletTransactionController::class);
// Route::apiResource('quizzes', QuizController::class);
// Route::apiResource('questions', QuestionController::class);
// Route::apiResource('quiz-attempts', QuizAttemptController::class);
// Route::apiResource('answers', AnswerController::class);
// Route::apiResource('ai-evaluations', AiEvaluationController::class);
// Route::apiResource('notifications', NotificationController::class);
// Route::apiResource('audit-logs', AuditLogController::class);
// Route::apiResource('escrow-transactions', EscrowTransactionController::class);
// });

Route::prefix('v1/admin')->middleware(['auth:sanctum', 'role:company_admin'])->group(function () {
    Route::put('/payment-settings', [ShamCashSettingController::class, 'update']);
    Route::get('/payments', [ShamCashPaymentController::class, 'index']);
    Route::get('/refunds', [RefundController::class, 'index']);
    Route::post('/refunds/{refund}/review', [RefundController::class, 'review']);
    Route::post('/refunds/{refund}/complete', [RefundController::class, 'complete']);
    Route::post('/payments/{payment}/review', [EnrollmentPaymentController::class, 'review']);
    // Existing generic admin endpoints remain available. Student targets are
    // automatically delegated to the stricter student security flow.
    Route::patch('/users/{user}/suspend', [AdminController::class, 'suspendUser']);
    Route::patch('/users/{user}/activate', [AdminController::class, 'activateUser']);

    // Explicit student endpoints for the student module.
    Route::patch('/students/{user}/suspend', [AdminController::class, 'suspendStudent']);
    Route::patch('/students/{user}/activate', [AdminController::class, 'activateStudent']);

});

Route::prefix('v1')->middleware(['auth:sanctum', 'role:student,company_admin', StudentDeviceWhenRequired::class])->group(function () {
    Route::get('/payment-settings', [ShamCashSettingController::class, 'show']);
    Route::get('/payment-settings/qr', [ShamCashSettingController::class, 'qr'])->name('payment-settings.qr');
    Route::get('/payment-proofs/{payment}', [ShamCashPaymentController::class, 'proof'])->name('payments.proof');
    Route::get('/refunds/{refund}', [RefundController::class, 'show'])->whereNumber('refund');
});

// Financial records cannot be created or edited anonymously.
Route::apiResource('v1/payments', PaymentController::class)
    ->middleware(['auth:sanctum', 'role:company_admin']);

Route::prefix('v1')->group(function (): void {
    Route::get('/governorates', [LocationController::class, 'governorates']);
    Route::get('/governorates/{id}/regions', [LocationController::class, 'regions']);
    Route::get('/regions/{id}/schools', [LocationController::class, 'schools']);
});
