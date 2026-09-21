<?php

/**
 * Tutoring Platform - Live CRUD API Smoke/Integration Test
 *
 * Runs against the ORIGINAL configured database and the REAL local HTTP API.
 * It DOES NOT run migrate:fresh and DOES NOT wipe the database.
 *
 * Safety strategy:
 * - Creates uniquely tagged temporary test records.
 * - Tests the 12 current CRUD API resources through HTTP.
 * - Tests important validation/business rules.
 * - Deletes only the records created by this script.
 * - Writes a timestamped report under storage/logs/.
 *
 * Usage:
 *   1) In terminal 1:
 *      php artisan serve
 *
 *   2) In terminal 2:
 *      php api_test_original_db.php
 *
 * Optional:
 *   API_BASE_URL=http://127.0.0.1:8000/api/v1 php api_test_original_db.php
 */

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\TeacherSubscription;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

$root = __DIR__;

if (! file_exists($root.'/vendor/autoload.php') || ! file_exists($root.'/bootstrap/app.php')) {
    fwrite(STDERR, "ERROR: Put this file in the Laravel project root (same folder as artisan).\n");
    exit(1);
}

require $root.'/vendor/autoload.php';

$app = require_once $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$baseUrl = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8000/api/v1', '/');
$dbConnection = (string) config('database.default');
$dbName = (string) config("database.connections.{$dbConnection}.database");
$runId = date('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8);
$tag = '__API_TEST_'.$runId.'__';

$passes = 0;
$fails = 0;
$report = [];
$created = [
    'teacher_user_id' => null,
    'student_user_id' => null,
    'student_id' => null,
    'teacher_id' => null,
    'subject_id' => null,
    'teacher_subject_id' => null,
    'unit_id' => null,
    'lesson_id' => null,
    'content_id' => null,
    'content_asset_id' => null,
    'group_id' => null,
    'enrollment_id' => null,
    'group_enrollment_id' => null,
    'plan_id' => null,
    'teacher_subscription_id' => null,
    'payment_id' => null,
];

function line(string $text = ''): void
{
    echo $text.PHP_EOL;
}

function record(string $text): void
{
    global $report;
    $report[] = '['.date('Y-m-d H:i:s').'] '.$text;
}

function pass(string $name): void
{
    global $passes;
    $passes++;
    $text = "[PASS] {$name}";
    line($text);
    record($text);
}

function fail(string $name, string $details): never
{
    global $fails;
    $fails++;
    $text = "[FAIL] {$name} :: {$details}";
    line($text);
    record($text);
    throw new RuntimeException($text);
}

function requestApi(string $method, string $uri, ?array $body = null)
{
    global $baseUrl;

    $client = Http::acceptJson()
        ->asJson()
        ->timeout(20)
        ->connectTimeout(5);

    $url = $baseUrl.'/'.ltrim($uri, '/');

    return match (strtoupper($method)) {
        'GET' => $client->get($url),
        'POST' => $client->post($url, $body ?? []),
        'PATCH' => $client->patch($url, $body ?? []),
        'PUT' => $client->put($url, $body ?? []),
        'DELETE' => $client->delete($url, $body ?? []),
        default => throw new InvalidArgumentException("Unsupported HTTP method: {$method}"),
    };
}

function expectStatus(string $name, $response, int|array $expected): void
{
    $allowed = is_array($expected) ? $expected : [$expected];

    if (! in_array($response->status(), $allowed, true)) {
        fail(
            $name,
            'Expected HTTP '.implode('|', $allowed).
            ', got '.$response->status().
            '. Body: '.$response->body()
        );
    }

    pass($name.' [HTTP '.$response->status().']');
}

function expectJsonPath(string $name, $response, string $path, mixed $expected): void
{
    $actual = data_get($response->json(), $path);

    if ($actual != $expected) {
        fail(
            $name,
            "JSON path {$path} expected ".json_encode($expected).
            ', got '.json_encode($actual).
            '. Body: '.$response->body()
        );
    }

    pass($name);
}

function responseId(string $name, $response): int
{
    $id = (int) data_get($response->json(), 'data.id', 0);

    if ($id <= 0) {
        fail($name, 'Response did not contain data.id. Body: '.$response->body());
    }

    return $id;
}

function safeDelete(string $modelClass, ?int $id): void
{
    if (! $id) {
        return;
    }

    try {
        $modelClass::query()->find($id)?->delete();
    } catch (Throwable $e) {
        record("[CLEANUP WARNING] {$modelClass} #{$id}: ".$e->getMessage());
    }
}

function saveReport(string $status): string
{
    global $root, $report, $passes, $fails, $dbName, $baseUrl, $runId;

    $dir = $root.'/storage/logs';
    if (! is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $path = $dir.'/api_crud_test_'.$runId.'.log';

    $header = [
        'Tutoring Platform - CRUD API Test Report',
        'Status: '.$status,
        'Date: '.date('Y-m-d H:i:s'),
        'Database: '.$dbName,
        'API Base URL: '.$baseUrl,
        'Passed checks: '.$passes,
        'Failed checks: '.$fails,
        str_repeat('=', 70),
    ];

    file_put_contents($path, implode(PHP_EOL, array_merge($header, $report)).PHP_EOL);

    return $path;
}

line(str_repeat('=', 72));
line('Tutoring Platform - LIVE API Test on ORIGINAL Database');
line(str_repeat('=', 72));
line('Database connection : '.$dbConnection);
line('Database name       : '.$dbName);
line('API Base URL        : '.$baseUrl);
line('Run ID              : '.$runId);
line();
line('IMPORTANT: This script will NOT run migrate:fresh.');
line('It creates temporary test records, tests them through HTTP, then cleans them.');
line();

if (! function_exists('readline')) {
    line('ERROR: readline() is unavailable in this PHP CLI.');
    exit(1);
}

$confirmation = trim((string) readline("Type TEST to continue on database '{$dbName}': "));
if ($confirmation !== 'TEST') {
    line('Cancelled. Nothing was changed.');
    exit(0);
}

record("Started test on database={$dbName}, baseUrl={$baseUrl}, runId={$runId}");

try {
    /*
    |--------------------------------------------------------------------------
    | Server check
    |--------------------------------------------------------------------------
    */
    try {
        $health = requestApi('GET', '/subjects?per_page=1');
    } catch (Throwable $e) {
        fail(
            'API server reachable',
            'Could not connect to '.$baseUrl.
            '. Start `php artisan serve` in another terminal. '.$e->getMessage()
        );
    }

    expectStatus('API server reachable + subjects route exists', $health, 200);

    /*
    |--------------------------------------------------------------------------
    | Prerequisite users created directly in the ORIGINAL DB
    | (No UserController exists in the current 12 APIs.)
    |--------------------------------------------------------------------------
    */
    $teacherUser = User::create([
        'name' => $tag.' Teacher User',
        'phone' => '09'.random_int(10000000, 99999999),
        'email' => strtolower($tag).'teacher@example.com',
        'password_hash' => 'ApiTestPassword123!',
        'role' => 'teacher',
        'status' => 'active',
    ]);
    $created['teacher_user_id'] = $teacherUser->id;
    pass('Prerequisite teacher User created in original DB');

    $studentUser = User::create([
        'name' => $tag.' Student User',
        'phone' => '09'.random_int(10000000, 99999999),
        'email' => strtolower($tag).'student@example.com',
        'password_hash' => 'ApiTestPassword123!',
        'role' => 'student',
        'status' => 'active',
    ]);
    $created['student_user_id'] = $studentUser->id;

    $student = Student::create([
        'user_id' => $studentUser->id,
        'grade' => '12',
        'school' => $tag.' School',
        'status' => 'active',
    ]);
    $created['student_id'] = $student->id;
    pass('Prerequisite student User + Student created in original DB');

    /*
    |--------------------------------------------------------------------------
    | 1) SUBJECTS
    |--------------------------------------------------------------------------
    */
    expectStatus('Subjects - GET list', requestApi('GET', '/subjects?per_page=20'), 200);

    $r = requestApi('POST', '/subjects', [
        'name' => $tag.' Mathematics',
        'description' => 'Temporary subject created by live API test',
        'status' => 'active',
    ]);
    expectStatus('Subjects - POST create', $r, 201);
    $created['subject_id'] = responseId('Subjects - capture ID', $r);

    expectStatus(
        'Subjects - GET show',
        requestApi('GET', '/subjects/'.$created['subject_id']),
        200
    );

    $r = requestApi('PATCH', '/subjects/'.$created['subject_id'], [
        'name' => $tag.' Advanced Mathematics',
    ]);
    expectStatus('Subjects - PATCH update', $r, 200);
    expectJsonPath('Subjects - updated name verified', $r, 'data.name', $tag.' Advanced Mathematics');

    /*
    |--------------------------------------------------------------------------
    | 2) TEACHERS
    |--------------------------------------------------------------------------
    */
    expectStatus('Teachers - GET list', requestApi('GET', '/teachers?per_page=20'), 200);

    $r = requestApi('POST', '/teachers', [
        'user_id' => $created['teacher_user_id'],
        'bio' => 'Temporary teacher created by live API test',
        'specialization' => 'Mathematics',
        'status' => 'active',
    ]);
    expectStatus('Teachers - POST create', $r, 201);
    $created['teacher_id'] = responseId('Teachers - capture ID', $r);

    expectStatus(
        'Teachers - GET show',
        requestApi('GET', '/teachers/'.$created['teacher_id']),
        200
    );

    $r = requestApi('PATCH', '/teachers/'.$created['teacher_id'], [
        'bio' => 'Updated by live API test',
        'specialization' => 'Mathematics & Physics',
    ]);
    expectStatus('Teachers - PATCH update', $r, 200);
    expectJsonPath('Teachers - specialization verified', $r, 'data.specialization', 'Mathematics & Physics');

    $r = requestApi('POST', '/teachers', [
        'user_id' => $created['teacher_user_id'],
    ]);
    expectStatus('Teachers - duplicate user rejected', $r, 422);

    $r = requestApi('POST', '/teachers', [
        'user_id' => $created['student_user_id'],
    ]);
    expectStatus('Teachers - student-role user rejected', $r, 422);

    /*
    |--------------------------------------------------------------------------
    | 3) TEACHER SUBJECTS
    |--------------------------------------------------------------------------
    */
    expectStatus('TeacherSubjects - GET list', requestApi('GET', '/teacher-subjects?per_page=20'), 200);

    $r = requestApi('POST', '/teacher-subjects', [
        'teacher_id' => $created['teacher_id'],
        'subject_id' => $created['subject_id'],
        'price' => 500000,
        'group_enabled' => true,
        'max_group_size' => 20,
        'status' => 'active',
    ]);
    expectStatus('TeacherSubjects - POST create', $r, 201);
    $created['teacher_subject_id'] = responseId('TeacherSubjects - capture ID', $r);

    expectStatus(
        'TeacherSubjects - GET show',
        requestApi('GET', '/teacher-subjects/'.$created['teacher_subject_id']),
        200
    );

    $r = requestApi('PATCH', '/teacher-subjects/'.$created['teacher_subject_id'], [
        'price' => 550000,
        'max_group_size' => 25,
    ]);
    expectStatus('TeacherSubjects - PATCH update', $r, 200);

    $r = requestApi('POST', '/teacher-subjects', [
        'teacher_id' => $created['teacher_id'],
        'subject_id' => $created['subject_id'],
        'price' => 100,
    ]);
    expectStatus('TeacherSubjects - duplicate teacher+subject rejected', $r, 422);

    /*
    |--------------------------------------------------------------------------
    | 4) UNITS
    |--------------------------------------------------------------------------
    */
    expectStatus('Units - GET list', requestApi('GET', '/units?per_page=20'), 200);

    $r = requestApi('POST', '/units', [
        'teacher_subject_id' => $created['teacher_subject_id'],
        'title' => $tag.' Limits',
        'description' => 'Temporary unit',
        'order_no' => 1,
        'status' => 'published',
    ]);
    expectStatus('Units - POST create', $r, 201);
    $created['unit_id'] = responseId('Units - capture ID', $r);

    expectStatus('Units - GET show', requestApi('GET', '/units/'.$created['unit_id']), 200);

    $r = requestApi('PATCH', '/units/'.$created['unit_id'], [
        'title' => $tag.' Limits and Continuity',
    ]);
    expectStatus('Units - PATCH update', $r, 200);

    /*
    |--------------------------------------------------------------------------
    | 5) LESSONS
    |--------------------------------------------------------------------------
    */
    expectStatus('Lessons - GET list', requestApi('GET', '/lessons?per_page=20'), 200);

    $r = requestApi('POST', '/lessons', [
        'unit_id' => $created['unit_id'],
        'title' => $tag.' Introduction to Limits',
        'description' => 'Temporary lesson',
        'order_no' => 1,
        'status' => 'published',
    ]);
    expectStatus('Lessons - POST create', $r, 201);
    $created['lesson_id'] = responseId('Lessons - capture ID', $r);

    expectStatus('Lessons - GET show', requestApi('GET', '/lessons/'.$created['lesson_id']), 200);

    $r = requestApi('PATCH', '/lessons/'.$created['lesson_id'], [
        'title' => $tag.' Introduction to Limits Updated',
    ]);
    expectStatus('Lessons - PATCH update', $r, 200);

    /*
    |--------------------------------------------------------------------------
    | 6) CONTENTS
    |--------------------------------------------------------------------------
    */
    expectStatus('Contents - GET list', requestApi('GET', '/contents?per_page=20'), 200);

    $r = requestApi('POST', '/contents', [
        'lesson_id' => $created['lesson_id'],
        'type' => 'video',
        'title' => $tag.' Limits Video',
        'description' => 'Temporary content',
        'order_no' => 1,
        'status' => 'published',
        'published_at' => date('Y-m-d H:i:s'),
    ]);
    expectStatus('Contents - POST create', $r, 201);
    $created['content_id'] = responseId('Contents - capture ID', $r);

    expectStatus('Contents - GET show', requestApi('GET', '/contents/'.$created['content_id']), 200);

    $r = requestApi('PATCH', '/contents/'.$created['content_id'], [
        'title' => $tag.' Limits Video Updated',
    ]);
    expectStatus('Contents - PATCH update', $r, 200);

    /*
    |--------------------------------------------------------------------------
    | 7) CONTENT ASSETS
    |--------------------------------------------------------------------------
    */
    expectStatus('ContentAssets - GET list', requestApi('GET', '/content-assets?per_page=20'), 200);

    $r = requestApi('POST', '/content-assets', [
        'content_id' => $created['content_id'],
        'disk' => 'private',
        'storage_key' => 'api-tests/'.$runId.'/master.m3u8',
        'mime_type' => 'application/vnd.apple.mpegurl',
        'file_size' => 1048576,
        'checksum' => 'checksum-'.$runId,
        'encryption_type' => 'AES-128',
        'encryption_status' => 'done',
        'status' => 'active',
    ]);
    expectStatus('ContentAssets - POST create', $r, 201);
    $created['content_asset_id'] = responseId('ContentAssets - capture ID', $r);

    expectStatus(
        'ContentAssets - GET show',
        requestApi('GET', '/content-assets/'.$created['content_asset_id']),
        200
    );

    $r = requestApi('PATCH', '/content-assets/'.$created['content_asset_id'], [
        'file_size' => 2097152,
    ]);
    expectStatus('ContentAssets - PATCH update', $r, 200);

    /*
    |--------------------------------------------------------------------------
    | 8) GROUPS
    |--------------------------------------------------------------------------
    */
    expectStatus('Groups - GET list', requestApi('GET', '/groups?per_page=20'), 200);

    $r = requestApi('POST', '/groups', [
        'teacher_subject_id' => $created['teacher_subject_id'],
        'name' => $tag.' Group',
        'capacity' => 10,
        'status' => 'active',
    ]);
    expectStatus('Groups - POST create', $r, 201);
    $created['group_id'] = responseId('Groups - capture ID', $r);

    expectStatus('Groups - GET show', requestApi('GET', '/groups/'.$created['group_id']), 200);

    $r = requestApi('PATCH', '/groups/'.$created['group_id'], [
        'name' => $tag.' Group Updated',
        'capacity' => 12,
    ]);
    expectStatus('Groups - PATCH update', $r, 200);

    $r = requestApi('POST', '/groups', [
        'teacher_subject_id' => $created['teacher_subject_id'],
        'name' => $tag.' Too Large Group',
        'capacity' => 999,
        'status' => 'active',
    ]);
    expectStatus('Groups - max_group_size rule enforced', $r, 422);

    /*
    |--------------------------------------------------------------------------
    | Prerequisite Enrollment (direct DB because Enrollment API is not among 12)
    |--------------------------------------------------------------------------
    */
    $enrollment = Enrollment::create([
        'student_id' => $created['student_id'],
        'teacher_id' => $created['teacher_id'],
        'enrollable_type' => TeacherSubject::class,
        'enrollable_id' => $created['teacher_subject_id'],
        'price' => 550000,
        'status' => 'active',
        'starts_at' => now(),
        'expires_at' => now()->addMonth(),
    ]);
    $created['enrollment_id'] = $enrollment->id;
    pass('Prerequisite active Enrollment created in original DB');

    /*
    |--------------------------------------------------------------------------
    | 9) GROUP ENROLLMENTS
    |--------------------------------------------------------------------------
    */
    expectStatus(
        'GroupEnrollments - GET list',
        requestApi('GET', '/group-enrollments?per_page=20'),
        200
    );

    $r = requestApi('POST', '/group-enrollments', [
        'group_id' => $created['group_id'],
        'enrollment_id' => $created['enrollment_id'],
        'status' => 'active',
    ]);
    expectStatus('GroupEnrollments - POST create', $r, 201);
    $created['group_enrollment_id'] = responseId('GroupEnrollments - capture ID', $r);

    expectStatus(
        'GroupEnrollments - GET show',
        requestApi('GET', '/group-enrollments/'.$created['group_enrollment_id']),
        200
    );

    $r = requestApi('POST', '/group-enrollments', [
        'group_id' => $created['group_id'],
        'enrollment_id' => $created['enrollment_id'],
        'status' => 'active',
    ]);
    expectStatus('GroupEnrollments - duplicate rejected', $r, 422);

    $r = requestApi('PATCH', '/group-enrollments/'.$created['group_enrollment_id'], [
        'status' => 'left',
    ]);
    expectStatus('GroupEnrollments - PATCH update', $r, 200);
    expectJsonPath('GroupEnrollments - left status verified', $r, 'data.status', 'left');

    /*
    |--------------------------------------------------------------------------
    | 10) SUBSCRIPTION PLANS
    |--------------------------------------------------------------------------
    */
    expectStatus(
        'SubscriptionPlans - GET list',
        requestApi('GET', '/subscription-plans?per_page=20'),
        200
    );

    $r = requestApi('POST', '/subscription-plans', [
        'name' => $tag.' Professional',
        'price' => 25,
        'billing_cycle' => 'monthly',
        'commission_rate' => 10,
        'max_students' => 1000,
        'max_storage' => 10737418240,
        'max_ai_usage' => 5000,
        'max_group_size' => 50,
        'status' => 'active',
    ]);
    expectStatus('SubscriptionPlans - POST create', $r, 201);
    $created['plan_id'] = responseId('SubscriptionPlans - capture ID', $r);

    expectStatus(
        'SubscriptionPlans - GET show',
        requestApi('GET', '/subscription-plans/'.$created['plan_id']),
        200
    );

    $r = requestApi('PATCH', '/subscription-plans/'.$created['plan_id'], [
        'price' => 30,
        'commission_rate' => 9,
    ]);
    expectStatus('SubscriptionPlans - PATCH update', $r, 200);

    /*
    |--------------------------------------------------------------------------
    | 11) TEACHER SUBSCRIPTIONS
    |--------------------------------------------------------------------------
    */
    expectStatus(
        'TeacherSubscriptions - GET list',
        requestApi('GET', '/teacher-subscriptions?per_page=20'),
        200
    );

    $r = requestApi('POST', '/teacher-subscriptions', [
        'teacher_id' => $created['teacher_id'],
        'plan_id' => $created['plan_id'],
        'starts_at' => date('Y-m-d H:i:s'),
        'ends_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        'auto_renew' => true,
        'status' => 'active',
    ]);
    expectStatus('TeacherSubscriptions - POST create', $r, 201);
    $created['teacher_subscription_id'] = responseId('TeacherSubscriptions - capture ID', $r);

    expectStatus(
        'TeacherSubscriptions - GET show',
        requestApi('GET', '/teacher-subscriptions/'.$created['teacher_subscription_id']),
        200
    );

    $r = requestApi('DELETE', '/teacher-subscriptions/'.$created['teacher_subscription_id']);
    expectStatus('TeacherSubscriptions - active delete blocked', $r, 409);

    $r = requestApi('DELETE', '/subscription-plans/'.$created['plan_id']);
    expectStatus('SubscriptionPlans - active subscription blocks delete', $r, 409);

    $r = requestApi('PATCH', '/teacher-subscriptions/'.$created['teacher_subscription_id'], [
        'auto_renew' => false,
        'status' => 'expired',
    ]);
    expectStatus('TeacherSubscriptions - PATCH update', $r, 200);
    expectJsonPath('TeacherSubscriptions - expired status verified', $r, 'data.status', 'expired');

    /*
    |--------------------------------------------------------------------------
    | 12) PAYMENTS
    |--------------------------------------------------------------------------
    */
    expectStatus('Payments - GET list', requestApi('GET', '/payments?per_page=20'), 200);

    $r = requestApi('POST', '/payments', [
        'payer_id' => $created['student_user_id'],
        'payable_type' => TeacherSubscription::class,
        'payable_id' => $created['teacher_subscription_id'],
        'amount' => 25,
        'currency' => 'USD',
        'provider' => 'live_api_test',
        'provider_reference' => 'TEST-'.$runId,
        'status' => 'pending',
        'paid_at' => null,
    ]);
    expectStatus('Payments - POST create', $r, 201);
    $created['payment_id'] = responseId('Payments - capture ID', $r);

    expectStatus('Payments - GET show', requestApi('GET', '/payments/'.$created['payment_id']), 200);

    $r = requestApi('PATCH', '/payments/'.$created['payment_id'], [
        'status' => 'succeeded',
        'paid_at' => date('Y-m-d H:i:s'),
    ]);
    expectStatus('Payments - PATCH succeeded', $r, 200);

    $r = requestApi('DELETE', '/payments/'.$created['payment_id']);
    expectStatus('Payments - succeeded delete blocked', $r, 409);

    $r = requestApi('PATCH', '/payments/'.$created['payment_id'], [
        'status' => 'failed',
        'paid_at' => null,
    ]);
    expectStatus('Payments - PATCH back to failed for cleanup', $r, 200);

    /*
    |--------------------------------------------------------------------------
    | DELETE ENDPOINTS - reverse dependency order
    |--------------------------------------------------------------------------
    */
    expectStatus(
        'GroupEnrollments - DELETE',
        requestApi('DELETE', '/group-enrollments/'.$created['group_enrollment_id']),
        200
    );
    $created['group_enrollment_id'] = null;

    expectStatus(
        'Payments - DELETE',
        requestApi('DELETE', '/payments/'.$created['payment_id']),
        200
    );
    $created['payment_id'] = null;

    expectStatus(
        'TeacherSubscriptions - DELETE',
        requestApi('DELETE', '/teacher-subscriptions/'.$created['teacher_subscription_id']),
        200
    );
    $created['teacher_subscription_id'] = null;

    expectStatus(
        'SubscriptionPlans - DELETE',
        requestApi('DELETE', '/subscription-plans/'.$created['plan_id']),
        200
    );
    $created['plan_id'] = null;

    expectStatus(
        'Groups - DELETE',
        requestApi('DELETE', '/groups/'.$created['group_id']),
        200
    );
    $created['group_id'] = null;

    // Enrollment has no current CRUD API, so remove only the test prerequisite directly.
    Enrollment::query()->whereKey($created['enrollment_id'])->delete();
    $created['enrollment_id'] = null;
    pass('Prerequisite Enrollment cleaned directly');

    expectStatus(
        'ContentAssets - DELETE',
        requestApi('DELETE', '/content-assets/'.$created['content_asset_id']),
        200
    );
    $created['content_asset_id'] = null;

    expectStatus(
        'Contents - DELETE',
        requestApi('DELETE', '/contents/'.$created['content_id']),
        200
    );
    $created['content_id'] = null;

    expectStatus(
        'Lessons - DELETE',
        requestApi('DELETE', '/lessons/'.$created['lesson_id']),
        200
    );
    $created['lesson_id'] = null;

    expectStatus(
        'Units - DELETE',
        requestApi('DELETE', '/units/'.$created['unit_id']),
        200
    );
    $created['unit_id'] = null;

    expectStatus(
        'TeacherSubjects - DELETE',
        requestApi('DELETE', '/teacher-subjects/'.$created['teacher_subject_id']),
        200
    );
    $created['teacher_subject_id'] = null;

    expectStatus(
        'Subjects - DELETE',
        requestApi('DELETE', '/subjects/'.$created['subject_id']),
        200
    );
    $created['subject_id'] = null;

    expectStatus(
        'Teachers - DELETE',
        requestApi('DELETE', '/teachers/'.$created['teacher_id']),
        200
    );
    $created['teacher_id'] = null;

    // Direct prerequisites cleanup.
    Student::query()->whereKey($created['student_id'])->delete();
    $created['student_id'] = null;

    User::query()->whereKey($created['student_user_id'])->delete();
    $created['student_user_id'] = null;

    User::query()->whereKey($created['teacher_user_id'])->delete();
    $created['teacher_user_id'] = null;

    pass('Prerequisite test users cleaned directly');

    $reportPath = saveReport('PASS');

    line();
    line(str_repeat('=', 72));
    line("ALL LIVE API TESTS PASSED");
    line("Passed checks: {$passes}");
    line("Failed checks: {$fails}");
    line("Original database: {$dbName}");
    line("Temporary test data: cleaned");
    line("Report: {$reportPath}");
    line(str_repeat('=', 72));
    exit(0);

} catch (Throwable $e) {
    record('[EXCEPTION] '.$e->getMessage());

    line();
    line('A test failed. Cleaning only the temporary records created by this run...');

    // Best-effort cleanup in reverse dependency order.
    safeDelete(GroupEnrollment::class, $created['group_enrollment_id']);
    safeDelete(Payment::class, $created['payment_id']);
    safeDelete(TeacherSubscription::class, $created['teacher_subscription_id']);
    safeDelete(SubscriptionPlan::class, $created['plan_id']);
    safeDelete(Group::class, $created['group_id']);
    safeDelete(Enrollment::class, $created['enrollment_id']);
    safeDelete(ContentAsset::class, $created['content_asset_id']);
    safeDelete(Content::class, $created['content_id']);
    safeDelete(Lesson::class, $created['lesson_id']);
    safeDelete(Unit::class, $created['unit_id']);
    safeDelete(TeacherSubject::class, $created['teacher_subject_id']);
    safeDelete(Subject::class, $created['subject_id']);
    safeDelete(Teacher::class, $created['teacher_id']);
    safeDelete(Student::class, $created['student_id']);
    safeDelete(User::class, $created['student_user_id']);
    safeDelete(User::class, $created['teacher_user_id']);

    $reportPath = saveReport('FAIL');

    line();
    line(str_repeat('=', 72));
    line('TEST RESULT: FAIL');
    line("Passed checks before failure: {$passes}");
    line("Failed checks: {$fails}");
    line("Report: {$reportPath}");
    line(str_repeat('=', 72));
    exit(1);
}
