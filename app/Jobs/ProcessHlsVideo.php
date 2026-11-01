<?php

namespace App\Jobs;

use App\Models\ContentAsset;
use App\Models\VideoAsset;
use App\Services\HlsVideos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessHlsVideo implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    public function __construct(public int $assetId, public string $fingerprint)
    {
        $this->onConnection(config('media.hls_queue_connection', 'media'));
        $this->onQueue(config('media.hls_queue', 'media'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('hls-video-'.$this->assetId))->releaseAfter(60)->expireAfter(3800)];
    }

    public function handle(HlsVideos $videos): void
    {
        $asset = ContentAsset::find($this->assetId);
        if (! $asset || $asset->status !== 'active' || $videos->fingerprint($asset) !== $this->fingerprint) {
            return;
        }
        $video = $asset->videoAsset;
        if ($video?->processing_status === 'ready' && $video->source_fingerprint === $this->fingerprint) {
            return;
        }
        $videos->process($asset, $this->fingerprint);
    }

    public function failed(?Throwable $error): void
    {
        VideoAsset::where('content_asset_id', $this->assetId)->where('source_fingerprint', $this->fingerprint)
            ->where('processing_status', '!=', 'ready')->update(['processing_status' => 'failed', 'processing_error' => 'HLS_PROCESSING_FAILED']);
        ContentAsset::whereKey($this->assetId)->whereHas('videoAsset', fn ($q) => $q->where('source_fingerprint', $this->fingerprint)->where('processing_status', 'failed'))
            ->update(['encryption_status' => 'failed']);
    }
}
