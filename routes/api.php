<?php

use App\Http\Controllers\Api\V1\ContentAssetController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\GroupEnrollmentController;
use App\Http\Controllers\Api\V1\LessonController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\TeacherController;
use App\Http\Controllers\Api\V1\TeacherSubjectController;
use App\Http\Controllers\Api\V1\TeacherSubscriptionController;
use App\Http\Controllers\Api\V1\UnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('throttle:api')
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
    });
