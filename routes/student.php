<?php

use App\Http\Controllers\Api\V1\AnswerController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AvailabilityBookingController;
use App\Http\Controllers\Api\V1\AvailableSessionController;
use App\Http\Controllers\Api\V1\CurriculumBrowseController;
use App\Http\Controllers\Api\V1\EnrollmentPaymentController;
use App\Http\Controllers\Api\V1\IndividualBookingController;
use App\Http\Controllers\Api\V1\OnlineLearningController;
use App\Http\Controllers\Api\V1\PaidLessonController;
use App\Http\Controllers\Api\V1\PhysicalAvailabilityController;
use App\Http\Controllers\Api\V1\PhysicalTeachingController;
use App\Http\Controllers\Api\V1\ProfileAvatarController;
use App\Http\Controllers\Api\V1\QuizAttemptController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ShamCashPaymentController;
use App\Http\Controllers\Api\V1\StudentAuthController;
use App\Http\Controllers\Api\V1\StudentBookingController;
use App\Http\Controllers\Api\V1\StudentContentAssetController;
use App\Http\Controllers\Api\V1\StudentEnrollmentController;
use App\Http\Controllers\Api\V1\StudentGroupReadController;
use App\Http\Controllers\Api\V1\StudentLearningController;
use App\Http\Controllers\Api\V1\StudentNotificationController;
use App\Http\Controllers\Api\V1\StudentProfileController;
use App\Http\Controllers\Api\V1\StudentQuizReadController;
use App\Http\Controllers\Api\V1\TeacherCalendarController;
use App\Http\Controllers\Api\V1\TeacherDirectoryController;
use App\Http\Middleware\StudentDeviceWhenRequired;
use Illuminate\Support\Facades\Route;

// Student authentication routes.
// bootstrap/app.php mounts this file under /api/v1, therefore the public URLs
// remain /api/v1/auth/... exactly as required by the SDD.
Route::prefix('auth')->middleware('throttle:10,1')->group(function (): void {
    Route::post('/register', [StudentAuthController::class, 'register']);
    Route::post('/login', [StudentAuthController::class, 'studentLogin']);
    Route::post('/refresh', [StudentAuthController::class, 'studentRefresh']);
    Route::post('/device/transfer', [StudentAuthController::class, 'studentDeviceTransfer']);

    Route::post('/logout', [StudentAuthController::class, 'studentLogout'])
        ->middleware(['auth:sanctum', 'role:student', 'student.device']);
});

Route::get('/me', [StudentAuthController::class, 'studentMe'])
    ->middleware(['auth:sanctum', 'role:student', 'student.device']);

// Student enrollment / purchase APIs from the SDD.
Route::middleware(['auth:sanctum', 'role:student', 'student.device'])->group(function (): void {
    Route::post('/teachers/{teacher}/bookings', [IndividualBookingController::class, 'store']);
    Route::get('/teachers/{teacher}/available-sessions', [AvailableSessionController::class, 'index']);
    Route::get('/student/bookings', [StudentBookingController::class, 'index']);
    Route::post('/student/enrollments/{enrollment}/payments', [EnrollmentPaymentController::class, 'store']);
    Route::post('/payments/checkout', [ShamCashPaymentController::class, 'checkout']);
    Route::get('/teachers/{teacher}/paid-slots', fn () => response()->json(['status' => false, 'code' => 'PHYSICAL_CASH_ONLY', 'message' => 'دفع الجلسات الفيزيائية لدى الأستاذ. استخدم عروض ومواعيد التدريس الفيزيائي.'], 410));
    Route::get('/student/paid-bookings', [PaidLessonController::class, 'index']);
    Route::get('/student/paid-bookings/{booking}', [PaidLessonController::class, 'show']);
    Route::post('/student/paid-bookings/{booking}/reschedule', fn () => response()->json(['status' => false, 'code' => 'LEGACY_BOOKING_REQUIRES_SUPPORT', 'message' => 'هذا حجز مدفوع قديم. تواصل مع الدعم لمعالجته دون إنشاء دفعة فيزيائية جديدة.'], 410));
    Route::post('/refunds', [RefundController::class, 'store']);
    Route::get('/refunds/my', [RefundController::class, 'index']);
    Route::get('/payments/checkout/{checkout}/qr', [ShamCashPaymentController::class, 'checkoutQr'])->name('payments.checkout.qr');
    Route::post('/student/payments', [ShamCashPaymentController::class, 'store']);
    Route::get('/student/payments', [ShamCashPaymentController::class, 'index']);
    Route::get('/student/payments/{payment}', [EnrollmentPaymentController::class, 'show']);
    Route::get('/student/enrollments', [StudentEnrollmentController::class, 'index']);
    Route::get('/student/booking', [StudentEnrollmentController::class, 'current']);
    Route::post('/teacher-subjects/{id}/enroll', [StudentEnrollmentController::class, 'enrollTeacherSubject']);
    Route::post('/units/{id}/enroll', [StudentEnrollmentController::class, 'enrollUnit']);
    Route::post('/contents/{id}/enroll', [StudentEnrollmentController::class, 'enrollContent']);

    Route::get('/student/attendance/percentage', [AttendanceController::class, 'percentage']);

    Route::get('/quizzes/{quiz}/data', [QuizController::class, 'studentData']);
    Route::post('/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'start']);
    Route::post('/quiz-attempts/{quizAttempt}/answers', [AnswerController::class, 'submitForAttempt']);
});

Route::post('/contents/{id}/playback-token', [StudentContentAssetController::class, 'playbackToken'])
    ->middleware(['auth:sanctum', 'role:student', 'student.device']);

Route::get('/content-assets/{contentAsset}/play', [StudentContentAssetController::class, 'play'])
    ->middleware(['auth:sanctum', 'role:student', 'student.device', 'signed'])
    ->name('student.content.play');

// SDD session booking APIs - authenticated student is derived from Bearer token.
Route::middleware(['auth:sanctum', 'role:student', 'student.device'])->group(function (): void {
    Route::post('/sessions/{id}/book', [SessionController::class, 'book']);
    Route::delete('/sessions/{id}/booking', [SessionController::class, 'cancelBooking']);
});

// Student profile, learning and device approval contracts.
Route::get('/auth/device/transfer-requests/{transfer}', [StudentAuthController::class, 'transferStatus'])->middleware('throttle:30,1');
Route::post('/auth/device/transfer-requests', [StudentAuthController::class, 'studentDeviceTransfer'])->middleware('throttle:10,1');
Route::post('/auth/device/transfer-requests/{transfer}/claim', [StudentAuthController::class, 'claimTransfer'])->middleware('throttle:10,1');
Route::get('/teachers/{teacher}', [StudentLearningController::class, 'teacher']);
Route::middleware(['auth:sanctum', 'role:student', 'student.device'])->group(function (): void {
    Route::match(['put', 'patch'], '/profile', [StudentProfileController::class, 'update']);
    Route::get('/contents/{content}/assets', [StudentContentAssetController::class, 'assets']);
    Route::post('/content-assets/{contentAsset}/access-token', [StudentContentAssetController::class, 'accessToken']);
    Route::post('/student/contents/{content}/complete', [StudentLearningController::class, 'complete']);
    Route::get('/student/teacher-subjects/{teacherSubject}/progress', [StudentLearningController::class, 'progress']);
    Route::get('/student/teacher-subjects/{teacherSubject}', [StudentLearningController::class, 'course']);
    Route::get('/student/sessions/{session}', [StudentBookingController::class, 'show']);
    Route::get('/student/device-transfer-requests', [StudentAuthController::class, 'pendingTransfers']);
    Route::post('/student/device-transfer-requests/{transfer}/{decision}', [StudentAuthController::class, 'decideTransfer'])->whereIn('decision', ['approve', 'reject']);
    Route::get('/student/notifications', [StudentNotificationController::class, 'index']);
    Route::post('/student/notifications/{notification}/read', [StudentNotificationController::class, 'read']);
    Route::get('/teachers/{teacher}/available-slots', [AvailabilityBookingController::class, 'index']);
    Route::post('/teacher-availabilities/{availability}/book', [AvailabilityBookingController::class, 'store']);
    Route::post('/quiz-attempts/{quizAttempt}/submit', [QuizAttemptController::class, 'submit']);
    Route::get('/contents/{content}', [CurriculumBrowseController::class, 'show']);
    Route::get('/teachers/{teacher}/calendar', [TeacherCalendarController::class, 'index']);
    Route::get('/student/my-quizzes', [StudentQuizReadController::class, 'index']);
    Route::get('/student/my-quizzes/{quiz}/attempts', [StudentQuizReadController::class, 'attempts']);
    Route::get('/student/groups', [StudentGroupReadController::class, 'index']);
    Route::get('/student/groups/{group}/members', [StudentGroupReadController::class, 'members']);
    Route::get('/contents/{content}/quizzes', [CurriculumBrowseController::class, 'quizzes']);
});

Route::middleware(['auth:sanctum', 'role:student,teacher', StudentDeviceWhenRequired::class])->group(function () {
    Route::post('/profile/avatar', [ProfileAvatarController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('/profile/avatar', [ProfileAvatarController::class, 'destroy']);
});

Route::post('/student/register', [StudentAuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/student/login', [StudentAuthController::class, 'studentLogin'])->middleware('throttle:10,1');
Route::middleware(['auth:sanctum', 'role:student', 'student.device'])->group(function (): void {
    Route::get('/student/profile', [StudentAuthController::class, 'studentMe']);
    Route::match(['PUT', 'PATCH', 'POST'], '/student/profile', [StudentProfileController::class, 'update']);
    Route::get('/student/teachers', [TeacherDirectoryController::class, 'index']);
    Route::get('/student/teachers/{teacher}/physical/groups', [PhysicalTeachingController::class, 'groups']);
    Route::post('/student/groups/{group}/join', [PhysicalTeachingController::class, 'join']);
    Route::post('/student/groups/{group}/join-request', [PhysicalTeachingController::class, 'join']);
    Route::post('/student/groups/{group}/withdraw', [PhysicalTeachingController::class, 'withdraw']);
    Route::get('/student/teachers/{teacher}/physical/individual-offers', [PhysicalTeachingController::class, 'offers']);
    Route::get('/student/teachers/{teacher}/availability', [PhysicalAvailabilityController::class, 'index']);
    Route::post('/student/individual-bookings', [PhysicalTeachingController::class, 'book']);
    Route::get('/student/teachers/{teacher}/online-content', [OnlineLearningController::class, 'tree']);
    Route::get('/student/lessons/{lesson}/content', [OnlineLearningController::class, 'content']);
    Route::post('/student/lessons/{lesson}/complete', [OnlineLearningController::class, 'complete']);
    Route::get('/student/teacher-subjects/{teacherSubject}/learning-summary', [OnlineLearningController::class, 'progress']);
    Route::get('/student/refunds/eligibility', [RefundController::class, 'eligibility']);
    Route::get('/student/refunds', [RefundController::class, 'index']);
    Route::post('/student/refunds', [RefundController::class, 'store']);
    Route::get('/student/refunds/{refund}', [RefundController::class, 'show'])->whereNumber('refund');
    Route::patch('/student/refunds/{refund}/payout-details', [RefundController::class, 'payout']);
});
