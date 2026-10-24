<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

// Execute from the private, exact-commit release directory. Never expose over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0077);
$target = '/home/mand/liqaa_app';
$stage = realpath(dirname(__DIR__));
$release = $argv[1] ?? '';
if (! preg_match('/^[a-f0-9]{40}$/', $release) || $stage !== '/home/mand/liqaa-payments-'.substr($release, 0, 12)
    || ! is_file($target.'/artisan')) {
    throw new RuntimeException('Unexpected release, staging directory, or application target.');
}
$manifest = json_decode(file_get_contents($stage.'/release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if ($manifest['commit'] !== $release || ! hash_equals($manifest['composer_lock'], hash_file('sha256', $target.'/composer.lock'))) {
    throw new RuntimeException('Release identity or installed dependency lock differs.');
}
$files = $manifest['files'];
foreach ($files as $path => $hash) {
    if (str_contains($path, '..') || ! preg_match('#^(app/|routes/|config/|database/migrations/|database/seeders/ShamCashDemoSeeder\.php$|database/seeders/data/sham-cash-demo-qr\.png$|bootstrap/app\.php$)#', $path)
        || ! is_file($stage.'/'.$path) || is_link($target.'/'.$path)
        || ! hash_equals($hash, hash_file('sha256', $stage.'/'.$path))) {
        throw new RuntimeException('Invalid canonical release entry: '.$path);
    }
}
require $target.'/vendor/autoload.php';
$app = require $target.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if ($db->getDriverName() !== 'mysql') {
    throw new RuntimeException('Expected production MySQL database.');
}
$changed = array_filter($files, fn ($hash, $path) => ! is_file($target.'/'.$path)
    || hash_file('sha256', $target.'/'.$path) !== $hash, ARRAY_FILTER_USE_BOTH);
echo 'PREFLIGHT_OK release='.$release.' files='.count($files).' changed='.count($changed).PHP_EOL;
if (($argv[2] ?? '') !== '--apply') {
    exit;
}

$backup = '/home/mand/liqaa-payments-backup-'.gmdate('Ymd-His').'-'.substr($release, 0, 12);
if (! mkdir($backup, 0700)) {
    throw new RuntimeException('Cannot create private backup directory.');
}
$run = function (array $command) use ($target): void {
    (new Process($command, $target))->setTimeout(240)->mustRun(function ($type, $output) {
        echo $output;
    });
};
$artisan = fn (array $args) => $run(array_merge([PHP_BINARY, 'artisan'], $args));
$run(['tar', '--exclude=./vendor', '--exclude=./node_modules', '--exclude=./storage', '--exclude=./bootstrap/cache', '-czf', $backup.'/code.tar.gz', '-C', $target, '.']);
$existing = [];
foreach ($changed as $path => $hash) {
    if (is_file($target.'/'.$path)) {
        $existing[] = $path;
        if (! is_dir(dirname($backup.'/files/'.$path))) {
            mkdir(dirname($backup.'/files/'.$path), 0700, true);
        }
        if (! copy($target.'/'.$path, $backup.'/files/'.$path)) {
            throw new RuntimeException('Code backup failed: '.$path);
        }
    }
}
file_put_contents($backup.'/manifest.json', json_encode(['release' => $release, 'changed' => $changed, 'existing' => $existing], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'BACKUP='.$backup.PHP_EOL;
$down = false;
$copied = [];
try {
    $artisan(['down', '--retry=30']);
    $down = true;
    $config = $db->getConfig();
    $dump = new Process(['mysqldump', '--host='.$config['host'], '--port='.($config['port'] ?? 3306),
        '--user='.$config['username'], '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', $config['database']],
        $target, ['MYSQL_PWD' => $config['password']]);
    $stream = gzopen($backup.'/database.sql.gz', 'wb6');
    if (! $stream) {
        throw new RuntimeException('Cannot open database backup.');
    }
    try {
        $dump->setTimeout(240)->run(function ($type, $output) use ($stream) {
            if ($type === Process::OUT && gzwrite($stream, $output) !== strlen($output)) {
                throw new RuntimeException('Database backup write failed.');
            }
        });
    } finally {
        gzclose($stream);
    }
    if (! $dump->isSuccessful() || filesize($backup.'/database.sql.gz') < 100) {
        throw new RuntimeException('Database backup failed; no source files were replaced.');
    }
    $run(['gzip', '-t', $backup.'/database.sql.gz']);
    echo 'FULL_DATABASE_BACKUP_OK'.PHP_EOL;
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
    // Only this feature's additive migrations. Never run fresh/reset or seed customer data.
    foreach (['2026_10_24_000001_add_sham_cash_payments.php', '2026_10_24_000002_add_paid_bookings_and_manual_refunds.php'] as $migration) {
        $artisan(['migrate', '--path=database/migrations/'.$migration, '--force', '--no-ansi']);
    }
    $artisan(['optimize:clear', '--no-ansi']);
    $artisan(['route:cache', '--no-ansi']);
    foreach ($files as $path => $hash) {
        if (! hash_equals($hash, hash_file('sha256', $target.'/'.$path))) {
            throw new RuntimeException('Installed hash mismatch: '.$path);
        }
    }
    if (in_array('--demo-payment-settings', $argv, true)) {
        $settings = $db->table('payment_settings')->where('id', 1)->first();
        if (! $settings->account_id) {
            $disk = Storage::disk('local');
            $path = 'payment-qr/sham-cash-demo.png';
            if (! $disk->put($path, file_get_contents($stage.'/database/seeders/data/sham-cash-demo-qr.png'))) {
                throw new RuntimeException('Unable to store requested demo QR.');
            }
            $db->table('payment_settings')->where('id', 1)->update(['account_id' => 'DEMO-NOT-FOR-PAYMENT', 'qr_path' => $path, 'updated_at' => now()]);
            echo 'DEMO_ONLY_QR_CONFIGURED'.PHP_EOL;
        } else {
            echo 'EXISTING_PAYMENT_ACCOUNT_PRESERVED'.PHP_EOL;
        }
    }
    $artisan(['payments:expire-holds', '--no-ansi']);
    file_put_contents($target.'/storage/app/deployed-release.json', json_encode(['commit' => $release, 'deployed_at' => gmdate('c'), 'backup' => $backup], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $artisan(['up', '--no-ansi']);
    $down = false;
    echo 'PAYMENTS_DEPLOYMENT_OK release='.$release.' verified_files='.count($files).PHP_EOL;
} catch (Throwable $error) {
    foreach ($copied as $path) {
        if (in_array($path, $existing, true)) {
            copy($backup.'/files/'.$path, $target.'/'.$path);
        } else {
            unlink($target.'/'.$path);
        }
    }
    $artisan(['optimize:clear', '--no-ansi']);
    echo 'PREVIOUS_CODE_RESTORED_ADDITIVE_SCHEMA_PRESERVED backup='.$backup.PHP_EOL;
    throw $error;
} finally {
    if ($down) {
        $artisan(['up', '--no-ansi']);
    }
}
