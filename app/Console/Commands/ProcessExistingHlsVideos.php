<?php

namespace App\Console\Commands;

use App\Models\ContentAsset;
use App\Services\HlsVideos;
use Illuminate\Console\Command;

class ProcessExistingHlsVideos extends Command
{
    protected $signature = 'media:process-videos {--asset= : One content_assets.id} {--force : Rebuild even a ready/pending video}';

    protected $description = 'Queue private video sources for HLS processing (no DRM)';

    public function handle(HlsVideos $hls): int
    {
        $count = 0;
        ContentAsset::where('status', 'active')->where('mime_type', 'like', 'video/%')->when($this->option('asset'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->chunkById(100, function ($assets) use ($hls, &$count) {
                foreach ($assets as $asset) {
                    // Existing rows default to pending but have no fingerprint/job yet.
                    $v = $asset->videoAsset;
                    if (! $this->option('force') && $v?->source_fingerprint && in_array($v->processing_status, ['pending', 'processing', 'ready'], true)) {
                        continue;
                    }
                    $hls->enqueue($asset);
                    $count++;
                }
            });
        $this->info("Queued {$count} videos. Run queue:work media --queue=media --timeout=3600 --tries=3.");

        return self::SUCCESS;
    }
}
