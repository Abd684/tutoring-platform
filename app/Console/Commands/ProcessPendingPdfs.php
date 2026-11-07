<?php

namespace App\Console\Commands;

use App\Models\ContentAsset;
use App\Services\PdfWorkerLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessPendingPdfs extends Command
{
    protected $signature = 'pdf:process-pending {--limit=5}';
    protected $description = 'Process eligible PDFs in upload order, one isolated renderer at a time';

    public function handle(PdfWorkerLock $locks): int
    {
        $lock = $locks->acquire('worker');
        if ($lock === null) { $this->line('PDF worker already running.'); return self::SUCCESS; }
        try {
            // A child may still be alive if its parent was killed. Do not reclaim it.
            $renderer = $locks->acquire('renderer');
            if ($renderer === null) { $this->line('PDF renderer still running.'); return self::SUCCESS; }
            $locks->release($renderer);
            $timeout = max(10, (int) config('pdf.processing_timeout_seconds', 300));
            ContentAsset::where('mime_type', 'application/pdf')->where('status', 'active')
                ->where('processing_status', 'processing')
                ->where(fn ($q) => $q->whereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<', now()->subSeconds($timeout + 60)))
                ->update(['processing_status' => 'failed', 'processing_started_at' => null,
                    'processing_error_code' => 'PDF_WORKER_INTERRUPTED', 'next_processing_at' => now()]);

            $started = microtime(true);
            $count = 0;
            $limit = max(1, min(20, (int) $this->option('limit')));
            while ($count < $limit && microtime(true) - $started < max(1, (int) config('pdf.worker_budget_seconds', 50))) {
                $asset = $this->claim();
                if ($asset === null) break;
                $count++;
                $exit = null;
                try {
                    $exit = $this->render($asset, $timeout);
                    $asset->refresh();
                    if ($exit === 75) {
                        // Busy is not a failed attempt. Leave the file in its original FIFO position.
                        $asset->update(['processing_status' => 'pending', 'processing_started_at' => null,
                            'processing_attempts' => max(0, $asset->processing_attempts - 1)]);
                        break;
                    }
                    if ($exit !== 0 || $asset->processing_status !== 'ready') {
                        $this->failed($asset, $asset->processing_error_code ?: 'PDF_WORKER_FAILED');
                    } else {
                        $asset->update(['processing_started_at' => null, 'next_processing_at' => null]);
                    }
                } catch (ProcessTimedOutException $e) {
                    $this->failed($asset, 'PDF_PROCESSING_TIMEOUT');
                } catch (Throwable $e) {
                    report($e);
                    $this->failed($asset, 'PDF_WORKER_FAILED');
                } finally {
                    // SIGKILL/timeouts bypass the renderer's own finally block.
                    if ($exit !== 75) File::deleteDirectory(storage_path('app/private/pdf-work/asset-'.$asset->id));
                }
                $asset->refresh();
                $this->line('PDF '.$asset->id.': '.$asset->processing_status.' (attempt '.$asset->processing_attempts.')');
            }
            $this->info('Handled '.$count.' PDF file(s).');
            return self::SUCCESS;
        } finally {
            $locks->release($lock);
        }
    }

    protected function claim(): ?ContentAsset
    {
        return DB::transaction(function () {
            $asset = ContentAsset::where('mime_type', 'application/pdf')->where('status', 'active')
                ->whereIn('processing_status', ['pending', 'failed'])
                ->where('processing_attempts', '<', max(1, (int) config('pdf.max_processing_attempts', 3)))
                ->where(fn ($q) => $q->whereNull('next_processing_at')->orWhere('next_processing_at', '<=', now()))
                ->orderBy('id')->lockForUpdate()->first();
            if ($asset) $asset->update(['processing_status' => 'processing',
                'processing_attempts' => $asset->processing_attempts + 1,
                'processing_started_at' => now(), 'processing_error_code' => null]);
            return $asset;
        });
    }

    protected function render(ContentAsset $asset, int $timeout): int
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'pdf:process-one', (string) $asset->id, '--no-interaction'], base_path());
        $process->setTimeout($timeout);
        $process->disableOutput();
        return $process->run();
    }

    protected function failed(ContentAsset $asset, string $code): void
    {
        $asset->refresh();
        $delay = max(1, (int) config('pdf.retry_delay_seconds', 60)) * (2 ** max(0, $asset->processing_attempts - 1));
        $asset->update(['processing_status' => 'failed', 'processing_error_code' => $code,
            'processing_started_at' => null, 'next_processing_at' => now()->addSeconds($delay)]);
    }
}
