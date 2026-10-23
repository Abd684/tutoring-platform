<?php

namespace App\Services;

use App\Models\ContentAsset;
use App\Models\VideoAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class VideoMetadata
{
    public function probe(ContentAsset $asset, ?string $localPath = null): ?int
    {
        if (! str_starts_with($asset->mime_type ?? '', 'video/')) {
            return null;
        }
        // ffprobe receives a local file only; disable network protocols embedded in media.
        $temporary = null;
        try {
            if ($localPath === null) {
                $disk = Storage::disk($asset->disk);
                if (! $disk->exists($asset->storage_key)) {
                    return null;
                }
                if (config('filesystems.disks.'.$asset->disk.'.driver') === 'local') {
                    $localPath = $disk->path($asset->storage_key);
                } else {
                    $temporary = tmpfile();
                    if ($temporary === false) {
                        return null;
                    }
                    $source = $disk->readStream($asset->storage_key);
                    if (! is_resource($source)) {
                        return null;
                    }
                    try {
                        stream_copy_to_stream($source, $temporary);
                    } finally {
                        fclose($source);
                    }
                    fflush($temporary);
                    $localPath = stream_get_meta_data($temporary)['uri'];
                }
            }
            $process = new Process([config('media.ffprobe_binary', 'ffprobe'), '-v', 'error',
                '-protocol_whitelist', 'file,pipe', '-show_entries', 'format=duration', '-of',
                'default=noprint_wrappers=1:nokey=1', $localPath]);
            $process->setTimeout(20)->run();
            $value = trim($process->getOutput());
            if (! $process->isSuccessful() || ! is_numeric($value) || (float) $value <= 0) {
                return null;
            }
            $seconds = (int) ceil((float) $value);
            VideoAsset::updateOrCreate(['content_asset_id' => $asset->id], ['duration_seconds' => $seconds]);

            return $seconds;
        } catch (\Throwable $e) {
            report($e);

            return null;
        } finally {
            if (is_resource($temporary)) {
                fclose($temporary);
            }
        }
    }
}
