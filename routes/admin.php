<?php

use App\Http\Controllers\Admin\AccountsController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\EnrollmentsController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\GroupsController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PaidBookingsController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SessionsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SubscriptionsController;
use App\Http\Middleware\AdminLocale;
use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\TeacherWorkspace;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware(AdminLocale::class)->group(function () {
    Route::get('login', [AuthController::class, 'form'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
    Route::middleware(EnsureAdministrator::class)->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', OverviewController::class)->name('overview');
        Route::post('students/{user}/device-transfers/{transfer}/approve', [AccountsController::class, 'approveDeviceTransfer'])
            ->whereNumber(['user', 'transfer'])->name('students.device-transfers.approve');
        Route::get('search', SearchController::class)->name('search');
        Route::controller(\App\Http\Controllers\Admin\NotificationsController::class)->prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/recipients', 'recipients')->name('recipients');
            Route::post('/', 'store')->name('store');
            Route::put('/{campaign}', 'update')->whereNumber('campaign')->name('update');
            Route::post('/{campaign}/dispatch', 'dispatch')->whereNumber('campaign')->name('dispatch');
            Route::post('/{campaign}/cancel', 'cancel')->whereNumber('campaign')->name('cancel');
        });
        foreach (['students' => 'student', 'teachers' => 'teacher'] as $path => $kind) {
            Route::get($path, [AccountsController::class, 'index'])->defaults('kind', $kind)->name($path.'.index');
            Route::get($path.'/create', [AccountsController::class, 'create'])->defaults('kind', $kind)->name($path.'.create');
            Route::post($path, [AccountsController::class, 'store'])->defaults('kind', $kind)->name($path.'.store');
            Route::get($path.'/{user}', [AccountsController::class, 'show'])->whereNumber('user')->defaults('kind', $kind)->name($path.'.show');
            Route::get($path.'/{user}/edit', [AccountsController::class, 'edit'])->whereNumber('user')->defaults('kind', $kind)->name($path.'.edit');
            Route::put($path.'/{user}', [AccountsController::class, 'update'])->whereNumber('user')->defaults('kind', $kind)->name($path.'.update');
            Route::patch($path.'/{user}/status', [AccountsController::class, 'status'])->whereNumber('user')->defaults('kind', $kind)->name($path.'.status');
        }
        Route::prefix('teachers/{teacherUser}')->whereNumber('teacherUser')->middleware(TeacherWorkspace::class)->group(function () {
            Route::get('groups', [GroupsController::class, 'index'])->name('groups.index');
            Route::get('groups/create', [GroupsController::class, 'create'])->name('groups.create');
            Route::post('groups', [GroupsController::class, 'store'])->name('groups.store');
            Route::get('groups/{group}', [GroupsController::class, 'show'])->whereNumber('group')->name('groups.show');
            Route::get('groups/{group}/edit', [GroupsController::class, 'edit'])->whereNumber('group')->name('groups.edit');
            Route::put('groups/{group}', [GroupsController::class, 'update'])->whereNumber('group')->name('groups.update');
            Route::post('groups/{group}/members', [GroupsController::class, 'add'])->name('groups.members.add');
            Route::delete('groups/{group}/members/{membership}', [GroupsController::class, 'remove'])->name('groups.members.remove');
            Route::get('sessions', [SessionsController::class, 'index'])->name('sessions.index');
            Route::get('sessions/create', [SessionsController::class, 'create'])->name('sessions.create');
            Route::post('sessions', [SessionsController::class, 'store'])->name('sessions.store');
            Route::get('sessions/{session}', [SessionsController::class, 'show'])->whereNumber('session')->name('sessions.show');
            Route::post('sessions/{session}/cancel', [SessionsController::class, 'cancel'])->name('sessions.cancel');
        });
        Route::get('groups', fn () => redirect()->route('admin.teachers.index'))->name('legacy.groups');
        Route::get('sessions', fn () => redirect()->route('admin.teachers.index'))->name('legacy.sessions');
        // Preserve bookmarks without restoring standalone navigation tabs.
        Route::get('groups/create', fn () => redirect()->route('admin.teachers.index'))->name('legacy.groups.create');
        Route::get('sessions/create', fn () => redirect()->route('admin.teachers.index'))->name('legacy.sessions.create');
        Route::get('groups/{group}/edit', fn (\App\Models\Group $group) => redirect()->route('admin.groups.edit', ['teacherUser'=>$group->teacherSubject->teacher->user_id,'group'=>$group]))->whereNumber('group')->name('legacy.groups.edit');
        Route::get('groups/{group}', fn (\App\Models\Group $group) => redirect()->route('admin.groups.show', ['teacherUser'=>$group->teacherSubject->teacher->user_id,'group'=>$group]))->whereNumber('group')->name('legacy.groups.show');
        Route::get('sessions/{session}', fn (\App\Models\Session $session) => redirect()->route('admin.sessions.show', ['teacherUser'=>$session->teacher->user_id,'session'=>$session]))->whereNumber('session')->name('legacy.sessions.show');
        // Require review of old open forms in the updated teacher workspace.
        foreach (['post'=>['groups','groups/{group}/members','sessions','sessions/{session}/cancel'], 'put'=>['groups/{group}'], 'delete'=>['groups/{group}/members/{membership}']] as $method=>$paths) {
            foreach ($paths as $path) Route::$method($path, fn () => redirect()->route('admin.teachers.index', [], 303)->with('warning','تم تحديث اللوحة؛ افتح صفحة المدرس وأعد مراجعة الطلب قبل حفظه.'));
        }
        Route::get('payments', [FinanceController::class, 'payments'])->name('payments.index');
        Route::get('payments/{payment}', [FinanceController::class, 'payment'])->whereNumber('payment')->name('payments.show');
        Route::post('payments/{payment}/review', [FinanceController::class, 'review'])->name('payments.review');
        Route::get('payments/{payment}/proof', [FinanceController::class, 'proof'])->name('payments.proof');
        Route::get('refunds', [FinanceController::class, 'refunds'])->name('refunds.index');
        Route::get('refunds/create', [FinanceController::class, 'createRefund'])->name('refunds.create');
        Route::post('refunds', [FinanceController::class, 'storeRefund'])->name('refunds.store');
        Route::get('refunds/{refund}', [FinanceController::class, 'refund'])->whereNumber('refund')->name('refunds.show');
        Route::get('refunds/{refund}/proof/{kind}', [FinanceController::class, 'refundProof'])->where('kind', 'refund|original')->name('refunds.proof');
        Route::post('refunds/{refund}/review', [FinanceController::class, 'reviewRefund'])->name('refunds.review');
        Route::post('refunds/{refund}/complete', [FinanceController::class, 'completeRefund'])->name('refunds.complete');
        Route::get('subscriptions', [SubscriptionsController::class, 'index'])->name('subscriptions.index');
        Route::post('subscriptions', [SubscriptionsController::class, 'store'])->name('subscriptions.store');
        Route::post('subscriptions/{subscription}/cancel', [SubscriptionsController::class, 'cancel'])->name('subscriptions.cancel');
        Route::get('plans', [SubscriptionsController::class, 'plans'])->name('plans.index');
        Route::get('plans/create', [SubscriptionsController::class, 'createPlan'])->name('plans.create');
        Route::post('plans', [SubscriptionsController::class, 'storePlan'])->name('plans.store');
        Route::get('plans/{plan}/edit', [SubscriptionsController::class, 'editPlan'])->name('plans.edit');
        Route::put('plans/{plan}', [SubscriptionsController::class, 'updatePlan'])->name('plans.update');
        Route::get('content', [ContentController::class, 'index'])->name('content.index');
        Route::get('lesson-files', [ContentController::class, 'upload'])->name('lesson-files');
        Route::get('content/create', [ContentController::class, 'create'])->name('content.create');
        Route::post('content', [ContentController::class, 'store'])->name('content.store');
        Route::get('content/{content}/edit', [ContentController::class, 'edit'])->name('content.edit');
        Route::put('content/{content}', [ContentController::class, 'update'])->name('content.update');
        Route::post('content/{content}/archive', [ContentController::class, 'archive'])->name('content.archive');
        Route::get('content/{content}/quiz', [ContentController::class, 'quiz'])->name('content.quiz');
        Route::put('content/{content}/quiz', [ContentController::class, 'saveQuiz'])->name('content.quiz.save');
        Route::get('content-assets/{asset}', [ContentController::class, 'asset'])->name('content.asset');
        Route::post('curriculum', [ContentController::class, 'hierarchy'])->name('curriculum.store');
        Route::put('curriculum/{kind}/{item}', [ContentController::class, 'updateHierarchy'])->where('kind', 'unit|lesson')->whereNumber('item')->name('curriculum.update');
        Route::get('settings', [SettingsController::class, 'index'])->name('settings');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::patch('settings/profile', [SettingsController::class, 'profile'])->name('settings.profile');
        Route::get('payment-settings', [FinanceController::class, 'paymentSettings'])->name('payment-settings');
        Route::put('payment-settings', [FinanceController::class, 'savePaymentSettings'])->name('payment-settings.update');
        Route::get('payment-settings/qr', [FinanceController::class, 'qr'])->name('payment-settings.qr');

        Route::get('enrollments', [EnrollmentsController::class, 'index'])->name('enrollments.index');
        Route::get('enrollments/create', [EnrollmentsController::class, 'create'])->name('enrollments.create');
        Route::post('enrollments', [EnrollmentsController::class, 'store'])->name('enrollments.store');
        Route::post('enrollments/{enrollment}/cancel', [EnrollmentsController::class, 'cancel'])->whereNumber('enrollment')->name('enrollments.cancel');
        Route::get('paid-bookings', [PaidBookingsController::class, 'index'])->name('bookings.index');
        Route::post('paid-bookings/{booking}/reschedule', [PaidBookingsController::class, 'reschedule'])->whereNumber('booking')->name('bookings.reschedule');
        Route::get('subjects', [CatalogController::class, 'subjects'])->name('subjects.index');
        Route::post('subjects', [CatalogController::class, 'saveSubject'])->name('subjects.store');
        Route::put('subjects/{subject}', [CatalogController::class, 'saveSubject'])->whereNumber('subject')->name('subjects.update');
        Route::get('geography', [CatalogController::class, 'geography'])->name('geography');
        Route::post('geography', [CatalogController::class, 'createGeography'])->name('geography.store');
        Route::put('geography/{kind}/{item}', [CatalogController::class, 'updateGeography'])->where('kind', 'governortate|region|school')->whereNumber('item')->name('geography.update');
        Route::get('audit', [AuditController::class, 'index'])->name('audit');

    });
});
