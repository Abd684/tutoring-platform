<?php

// Read-only production smoke checks. No tokens or test accounts are created.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$target = '/home/mand/liqaa_app';
require $target.'/vendor/autoload.php';
$app = require $target.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$release = json_decode(file_get_contents($target.'/storage/app/deployed-release.json'), true, 512, JSON_THROW_ON_ERROR);
if ($release['commit'] !== '782f2cf2385c0df7f758234b3e6b282045d8ae65') {
    throw new RuntimeException('Unexpected deployed release.');
}
$checks = [];
foreach ($app['router']->getRoutes() as $route) {
    if (str_starts_with($route->uri(), 'api/') && in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
        $checks[] = [$route->methods()[0], '/'.preg_replace('/\{[^}]+\}/', '999999', str_replace('{decision}', 'approve', $route->uri())), 401];
    }
}
$protectedCount = count($checks);
foreach (['/up', '/api/v1/governorates', '/api/v1/teachers', '/api/v1/teachers/directory',
    '/api/v1/teachers/featured', '/api/v1/teacher-subjects/most-requested'] as $path) {
    $checks[] = ['GET', $path, 200];
}
$teacher = App\Models\Teacher::where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->first();
if ($teacher) {
    $checks[] = ['GET', '/api/v1/teachers/'.$teacher->id, 200];
    $checks[] = ['GET', '/api/v1/teachers/'.$teacher->id.'/subjects', 200];
}
$course = App\Models\TeacherSubject::where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active'))
    ->whereHas('teacher', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')))->first();
if ($course) {
    $checks[] = ['GET', '/api/v1/teacher/subjects/'.$course->id.'/units', 200];
    $unit = $course->units()->where('status', 'published')->first();
    if ($unit) {
        $checks[] = ['GET', '/api/v1/units/'.$unit->id.'/lessons', 200];
        $lesson = $unit->lessons()->where('status', 'published')->first();
        if ($lesson) {
            $checks[] = ['GET', '/api/v1/lessons/'.$lesson->id.'/contents', 200];
        }
    }
}
$failed = [];
foreach (array_chunk($checks, 4) as $batch) {
    $multi = curl_multi_init();
    $handles = [];
    foreach ($batch as [$method, $path, $expected]) {
        $handle = curl_init('https://mandobe.net/liqaa'.$path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25]);
        if (! in_array($method, ['GET', 'HEAD'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, '{}');
        }
        curl_multi_add_handle($multi, $handle);
        $handles[] = [$handle, $method, $path, $expected];
    }
    do {
        curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 0.5);
        }
    } while ($running);
    foreach ($handles as [$handle, $method, $path, $expected]) {
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($status !== $expected) {
            $failed[] = "$method $path expected=$expected actual=$status error=".curl_error($handle);
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
}
$user = (new App\Models\User)->forceFill(['id' => -1, 'role' => 'teacher', 'status' => 'active']);
$user->setRelation('teacher', (new App\Models\Teacher)->forceFill(['id' => -1, 'status' => 'active']));
$active = Illuminate\Support\Facades\Gate::forUser($user)->allows('createOwn', App\Models\TeacherSubject::class);
$user->teacher->status = 'pending_review';
$pending = Illuminate\Support\Facades\Gate::forUser($user)->allows('createOwn', App\Models\TeacherSubject::class);
if (! $active || $pending) {
    $failed[] = 'Teacher create policy did not enforce activation.';
}
echo 'RELEASE='.$release['commit'].PHP_EOL;
echo 'HTTP_CHECKS='.count($checks).' AUTH_GUARDS='.$protectedCount.' PUBLIC_READS='.(count($checks) - $protectedCount).PHP_EOL;
echo 'TEACHER_POLICY active='.($active ? 'allowed' : 'denied').' pending='.($pending ? 'allowed' : 'denied').PHP_EOL;
foreach ($failed as $failure) {
    echo 'FAIL '.$failure.PHP_EOL;
}
echo $failed === [] ? 'SMOKE_OK'.PHP_EOL : 'SMOKE_FAILED'.PHP_EOL;
exit($failed === [] ? 0 : 1);
