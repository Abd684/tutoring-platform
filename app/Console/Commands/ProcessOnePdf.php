<?php

namespace App\Console\Commands;

use App\Models\ContentAsset;
use App\Services\PdfWorkerLock;
use App\Services\SecurePdfProcessor;
use Illuminate\Console\Command;

class ProcessOnePdf extends Command
{
    protected $signature = 'pdf:process-one {asset}';
    protected $description = 'Internal isolated PDF rendering subprocess';

    public function handle(PdfWorkerLock $locks, SecurePdfProcessor $processor): int
    {
        $lock = $locks->acquire('renderer');
        if ($lock === null) return 75;
        try {
            $asset = ContentAsset::findOrFail($this->argument('asset'));
            if ($asset->mime_type !== 'application/pdf' || $asset->status !== 'active'
                || $asset->processing_status !== 'processing') return self::FAILURE;
            $processor->process($asset);
            return $asset->fresh()->processing_status === 'ready' ? self::SUCCESS : self::FAILURE;
        } finally {
            $locks->release($lock);
        }
    }
}
