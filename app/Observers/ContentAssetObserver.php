<?php

namespace App\Observers;

use App\Models\ContentAsset;
use App\Services\HlsVideos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContentAssetObserver
{
    public function created(ContentAsset $asset): void
    {
        if (str_starts_with($asset->mime_type ?? '', 'video/')) {
            app(HlsVideos::class)->enqueue($asset);
        }
    }

    public function updated(ContentAsset $asset): void
    {
        $reactivated = $asset->wasChanged('status') && $asset->status === 'active' && $asset->videoAsset?->processing_status !== 'ready';
        if (($asset->wasChanged(['disk', 'storage_key', 'mime_type', 'checksum']) || $reactivated) && str_starts_with($asset->mime_type ?? '', 'video/')) {
            app(HlsVideos::class)->enqueue($asset);
        }
    }

    public function deleted(ContentAsset $asset): void
    {
        // Only the server-owned HLS namespace; never a request-supplied directory.
        DB::afterCommit(function () use ($asset) {
            if ($asset->disk !== 'public') {
                Storage::disk($asset->disk)->deleteDirectory('hls/'.(int) $asset->id);
            }
        });
    }
}
