<?php

// CLI only. Run from a private staging folder, never from the public web root.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0077);
$target = '/home/mand/liqaa_app';
$stage = '/home/mand/liqaa-release-782f2cf';
$release = '782f2cf2385c0df7f758234b3e6b282045d8ae65';
if (! is_file($target.'/artisan') || realpath(dirname(__FILE__)) !== $stage.'/ops') {
    throw new RuntimeException('Unexpected deployment location.');
}
$manifest = json_decode(file_get_contents($stage.'/verified-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest as $path => $hash) {
    if (str_contains($path, '..') || ! preg_match('#^(app/|routes/|config/|database/migrations/|bootstrap/app\.php$)#', $path)
        || ! is_file($stage.'/'.$path) || is_link($target.'/'.$path)
        || ! hash_equals($hash, hash_file('sha256', $stage.'/'.$path))) {
        throw new RuntimeException('Invalid release file: '.$path);
    }
}
if (hash_file('sha256', $target.'/composer.lock') !== hash_file('sha256', $stage.'/composer.lock')) {
    throw new RuntimeException('Installed dependency lock differs from the tested release.');
}
require $target.'/vendor/autoload.php';
$app = require $target.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql') {
    throw new RuntimeException('Expected the production MySQL database.');
}
if ($db->table('student_devices')->where('status', 'active')->where('is_active', true)
    ->select('student_id')->groupBy('student_id')->havingRaw('COUNT(*) > 1')->exists()) {
    throw new RuntimeException('Duplicate active device bindings require reconciliation before deployment.');
}
$legacy = $db->table('sessions')->where('type', 'INDIVIDUAL')->whereNull('enrollment_id')->get();
foreach ($legacy as $slot) {
    $start = Illuminate\Support\Carbon::parse($slot->starts_at);
    $end = Illuminate\Support\Carbon::parse($slot->ends_at);
    if ($slot->group_id !== null || ! in_array($slot->status, ['scheduled', 'cancelled'], true)
        || $start->toDateString() !== $end->toDateString() || $end->lte($start)
        || $db->table('attendance')->where('session_id', $slot->id)->exists()) {
        throw new RuntimeException('Legacy session '.$slot->id.' requires reconciliation.');
    }
}
$changed = array_filter($manifest, fn ($hash, $path) => ! is_file($target.'/'.$path)
    || hash_file('sha256', $target.'/'.$path) !== $hash, ARRAY_FILTER_USE_BOTH);
echo 'PREFLIGHT_OK files='.count($manifest).' changed='.count($changed).' legacy_slots='.count($legacy).PHP_EOL;
if (($argv[1] ?? '') !== '--apply') {
    exit;
}

$backup = '/home/mand/liqaa-backup-'.gmdate('Ymd-His').'-782f2cf';
if (! mkdir($backup, 0700)) {
    throw new RuntimeException('Cannot create backup directory.');
}
$run = function (array $command, string $cwd = '') use ($target): void {
    $process = new Symfony\Component\Process\Process($command, $cwd ?: $target);
    $process->setTimeout(180)->mustRun(function ($type, $output) { echo $output; });
};
$run(['tar', '--exclude=./vendor', '--exclude=./node_modules', '--exclude=./storage', '--exclude=./bootstrap/cache',
    '-czf', $backup.'/code.tar.gz', '-C', $target, '.']);
$existing = [];
foreach ($changed as $path => $hash) {
    if (is_file($target.'/'.$path)) {
        $existing[] = $path;
        if (! is_dir(dirname($backup.'/files/'.$path))) {
            mkdir(dirname($backup.'/files/'.$path), 0700, true);
        }
        if (! copy($target.'/'.$path, $backup.'/files/'.$path)) {
            throw new RuntimeException('Cannot back up '.$path);
        }
    }
}
file_put_contents($backup.'/manifest.json', json_encode(['release' => $release, 'changed' => $changed, 'existing' => $existing], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'BACKUP='.$backup.PHP_EOL;
$artisan = fn (array $args) => $run(array_merge([PHP_BINARY, 'artisan'], $args));
$down = false;
$copied = [];
try {
    $artisan(['down', '--retry=30']);
    $down = true;
    $config = $db->getConfig();
    $dump = new Symfony\Component\Process\Process(['mysqldump', '--host='.$config['host'], '--port='.($config['port'] ?? 3306),
        '--user='.$config['username'], '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', $config['database']],
        $target, ['MYSQL_PWD' => $config['password']]);
    $stream = gzopen($backup.'/database.sql.gz', 'wb6');
    if ($stream === false) {
        throw new RuntimeException('Cannot open database backup.');
    }
    $dumpError = '';
    try {
        $dump->setTimeout(180)->run(function ($type, $output) use ($stream, &$dumpError) {
            if ($type === Symfony\Component\Process\Process::OUT) {
                if (gzwrite($stream, $output) !== strlen($output)) {
                    throw new RuntimeException('Database backup write failed.');
                }
            } else {
                $dumpError .= $output;
            }
        });
    } finally {
        gzclose($stream);
    }
    if (! $dump->isSuccessful() || filesize($backup.'/database.sql.gz') < 100) {
        throw new RuntimeException('Database backup failed: '.$dumpError);
    }
    echo 'FULL_DATABASE_BACKUP_OK bytes='.filesize($backup.'/database.sql.gz').PHP_EOL;
    foreach ($changed as $path => $hash) {
        $destination = $target.'/'.$path;
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }
        if (! copy($stage.'/'.$path, $destination.'.deploy-tmp')) {
            throw new RuntimeException('Copy failed: '.$path);
        }
        chmod($destination.'.deploy-tmp', 0644);
        if (! rename($destination.'.deploy-tmp', $destination)) {
            throw new RuntimeException('Atomic replacement failed: '.$path);
        }
        $copied[] = $path;
    }
    $artisan(['migrate', '--force', '--no-ansi']);
    $artisan(['optimize:clear', '--no-ansi']);
    $artisan(['route:cache', '--no-ansi']);
    foreach ($manifest as $path => $hash) {
        if (! hash_equals($hash, hash_file('sha256', $target.'/'.$path))) {
            throw new RuntimeException('Installed hash mismatch: '.$path);
        }
    }
    file_put_contents($target.'/storage/app/deployed-release.json', json_encode(['commit' => $release, 'deployed_at' => gmdate('c'), 'backup' => $backup], JSON_PRETTY_PRINT));
    $artisan(['up', '--no-ansi']);
    $down = false;
    echo 'DEPLOYMENT_OK release='.$release.' verified_files='.count($manifest).PHP_EOL;
} catch (Throwable $error) {
    foreach ($copied as $path) {
        if (in_array($path, $existing, true)) {
            copy($backup.'/files/'.$path, $target.'/'.$path);
        } else {
            unlink($target.'/'.$path);
        }
    }
    $artisan(['optimize:clear', '--no-ansi']);
    echo 'PREVIOUS_CODE_RESTORED backup='.$backup.PHP_EOL;
    throw $error;
} finally {
    if ($down) {
        $artisan(['up', '--no-ansi']);
    }
}
