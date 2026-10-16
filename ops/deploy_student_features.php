<?php

// CLI only. The bundle is extracted outside the live application first.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = realpath($argv[1] ?? '');
$stage = dirname(__DIR__);
if ($target !== '/home/mand/liqaa_app' || ! is_file($target.'/artisan')) {
    throw new RuntimeException('Unexpected deployment target.');
}
$manifest = json_decode(file_get_contents($stage.'/student-feature-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest as $path => $hash) {
    if (str_contains($path, '..') || str_starts_with($path, '/') || ! preg_match('#^(app/|routes/|config/media\.php$|bootstrap/app\.php$|database/migrations/|docs/)#', $path)
        || ! hash_equals($hash, hash_file('sha256', $stage.'/'.$path))) {
        throw new RuntimeException('Invalid bundle entry: '.$path);
    }
}
require $target.'/vendor/autoload.php';
$app = require $target.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$backup = '/home/mand/liqaa-student-backup-'.gmdate('Ymd-His');
if (! mkdir($backup, 0700)) throw new RuntimeException('Backup directory could not be created.');
$snapshot = [];
foreach (['users', 'teachers', 'contents', 'video_assets'] as $table) {
    $snapshot[$table] = ['schema' => Illuminate\Support\Facades\DB::select('SHOW CREATE TABLE `'.$table.'`'),
        'rows' => Illuminate\Support\Facades\DB::table($table)->get()->toArray()];
}
file_put_contents($backup.'/database-before.json.gz', gzencode(json_encode($snapshot, JSON_THROW_ON_ERROR)));
chmod($backup.'/database-before.json.gz', 0600);
$existing = [];
foreach ($manifest as $path => $hash) {
    if (is_file($target.'/'.$path)) {
        $existing[] = $path;
        if (! is_dir(dirname($backup.'/'.$path))) mkdir(dirname($backup.'/'.$path), 0700, true);
        if (! copy($target.'/'.$path, $backup.'/'.$path)) throw new RuntimeException('Code backup failed.');
    }
}
file_put_contents($backup.'/manifest.json', json_encode(['bundle' => $manifest, 'existing' => $existing], JSON_PRETTY_PRINT));
echo 'BACKUP='.$backup.PHP_EOL;
$run = function (array $args) use ($target): void {
    $process = new Symfony\Component\Process\Process(array_merge([PHP_BINARY, 'artisan'], $args), $target);
    $process->setTimeout(120)->mustRun(function ($type, $output) { echo $output; });
};
$down = false;
try {
    $run(['down', '--retry=30']);
    $down = true;
    foreach ($manifest as $path => $hash) {
        if (! is_dir(dirname($target.'/'.$path))) mkdir(dirname($target.'/'.$path), 0755, true);
        if (! copy($stage.'/'.$path, $target.'/'.$path)) throw new RuntimeException('Copy failed: '.$path);
    }
    $run(['migrate', '--force']);
    $run(['optimize:clear']);
    $run(['up']);
    $down = false;
    echo 'STUDENT_FEATURES_DEPLOYED files='.count($manifest).PHP_EOL;
} catch (Throwable $e) {
    // Preserve additive schema and restore previous code. New files are harmless without routes.
    foreach ($existing as $path) copy($backup.'/'.$path, $target.'/'.$path);
    $run(['optimize:clear']);
    throw $e;
} finally {
    if ($down) $run(['up']);
}
