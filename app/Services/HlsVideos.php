<?php

namespace App\Services;

use App\Jobs\ProcessHlsVideo;
use App\Models\ContentAsset;
use App\Models\VideoAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class HlsVideos
{
    public function fingerprint(ContentAsset $asset): string
    {
        return hash('sha256', implode('|', [$asset->disk, $asset->storage_key, $asset->checksum, $asset->mime_type]));
    }

    public function enqueue(ContentAsset $asset): void
    {
        if (! str_starts_with($asset->mime_type ?? '', 'video/') || $asset->status !== 'active') {
            return;
        }
        $fingerprint = $this->fingerprint($asset);
        VideoAsset::updateOrCreate(['content_asset_id' => $asset->id], [
            'processing_status' => 'pending', 'processing_error' => null, 'source_fingerprint' => $fingerprint,
            'hls_manifest_key' => null, 'renditions' => null, 'processed_at' => null,
        ]);
        $asset->forceFill(['encryption_type' => 'hls-aes128', 'encryption_status' => 'pending'])->saveQuietly();
        ProcessHlsVideo::dispatch($asset->id, $fingerprint);
    }

    public function process(ContentAsset $asset, string $fingerprint): void
    {
        if ($asset->disk === 'public') {
            throw new RuntimeException('HLS requires private storage.');
        }
        $id = (string) Str::uuid();
        $work = storage_path('app/private/hls-work/'.$id);
        $directory = 'hls/'.$asset->id.'/'.$id;
        $disk = Storage::disk($asset->disk);
        $previousDirectory = $asset->videoAsset?->hls_directory;
        File::ensureDirectoryExists($work.'/output');
        $published = false;
        VideoAsset::where('content_asset_id', $asset->id)->where('source_fingerprint', $fingerprint)
            ->update(['processing_status' => 'processing', 'processing_error' => null]);
        ContentAsset::whereKey($asset->id)->whereHas('videoAsset', fn ($q) => $q->where('source_fingerprint', $fingerprint))
            ->update(['encryption_status' => 'processing']);
        try {
            $input = $disk->readStream($asset->storage_key);
            if (! is_resource($input)) {
                throw new RuntimeException('Video source missing.');
            }
            try {
                $output = fopen($work.'/source', 'wb');
                if (! is_resource($output)) {
                    throw new RuntimeException('Cannot create working file.');
                }
                try {
                    if (stream_copy_to_stream($input, $output) === false) {
                        throw new RuntimeException('Cannot copy video source.');
                    }
                } finally {
                    fclose($output);
                }
            } finally {
                fclose($input);
            }
            $meta = $this->probe($work.'/source');
            $video = collect($meta['streams'] ?? [])->firstWhere('codec_type', 'video');
            $duration = (float) ($meta['format']['duration'] ?? 0);
            if (! $video || $duration <= 0 || $duration > config('media.hls_max_duration_seconds', 14400)) {
                throw new RuntimeException('Invalid video dimensions or duration.');
            }
            $height = (int) ($video['height'] ?? 0);
            $width = (int) ($video['width'] ?? 0);
            if ($height < 2 || $width < 2 || $height > 8192 || $width > 8192) {
                throw new RuntimeException('Unsupported video dimensions.');
            }
            foreach ($video['side_data_list'] ?? [] as $side) {
                if (abs((int) ($side['rotation'] ?? 0)) % 180 === 90) {
                    [$height, $width] = [$width, $height];
                    break;
                }
            }
            $renditions = [];
            $master = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-INDEPENDENT-SEGMENTS\n";
            foreach (config('media.hls_renditions') as $i => $r) {
                // Always produce a base rendition, never upscale to extra higher renditions.
                if ($i > 0 && $height < $r['height']) {
                    continue;
                }
                $name = $r['name'];
                if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
                    throw new RuntimeException('Invalid rendition configuration.');
                }
                File::ensureDirectoryExists($work.'/output/'.$name);
                // Standard HLS AES-128, independent key per rendition/generation.
                // This is not DRM: authorized players receive the key through our protected route.
                File::put($work.'/output/'.$name.'/key.bin', random_bytes(16));
                File::put($work.'/'.$name.'-key-info', "key.bin\n".str_replace('\\', '/', $work.'/output/'.$name.'/key.bin')."\n");
                $seconds = (int) config('media.hls_segment_seconds', 6);
                $filter = "scale=w='min({$r['width']},iw)':h='min({$r['height']},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2,setsar=1";
                $command = [config('media.ffmpeg_binary'), '-nostdin', '-y', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov,matroska,webm', '-i', $work.'/source',
                    '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn', '-map_metadata', '-1', '-vf', $filter,
                    '-c:v', 'libx264', '-preset', 'fast', '-threads', '2', '-pix_fmt', 'yuv420p', '-r', '30', '-g', (string) (30 * $seconds), '-sc_threshold', '0',
                    '-force_key_frames', "expr:gte(t,n_forced*{$seconds})", '-b:v', $r['bitrate'].'k', '-maxrate', $r['bitrate'].'k', '-bufsize', ($r['bitrate'] * 2).'k',
                    '-c:a', 'aac', '-b:a', '96k', '-ac', '2', '-f', 'hls', '-hls_time', (string) $seconds, '-hls_playlist_type', 'vod', '-hls_flags', 'independent_segments',
                    '-hls_key_info_file', $work.'/'.$name.'-key-info',
                    '-hls_segment_filename', $name.'/segment_%06d.ts', $name.'/index.m3u8'];
                $process = new Process($command, $work.'/output');
                $process->setTimeout((int) config('media.hls_process_timeout', 900))->mustRun();
                $bandwidth = ($r['bitrate'] + 128) * 1100;
                $master .= "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth}\n{$name}/index.m3u8\n";
                $renditions[] = ['name' => $name, 'max_width' => $r['width'], 'max_height' => min($height, $r['height']), 'bandwidth' => $bandwidth];
            }
            if ($renditions === []) {
                throw new RuntimeException('No video renditions configured.');
            }
            File::put($work.'/output/master.m3u8', $master);
            foreach (File::allFiles($work.'/output') as $file) {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $stream = fopen($file->getPathname(), 'rb');
                try {
                    if (! $disk->put($directory.'/'.$relative, $stream, ['visibility' => 'private'])) {
                        throw new RuntimeException('HLS upload failed.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
            // Do not attach an old job's output to a replaced/deleted source.
            $published = DB::transaction(function () use ($asset, $fingerprint, $directory, $duration, $renditions) {
                $current = ContentAsset::whereKey($asset->id)->lockForUpdate()->first();
                if (! $current || $current->status !== 'active' || $this->fingerprint($current) !== $fingerprint) {
                    return false;
                }
                VideoAsset::where('content_asset_id', $asset->id)->where('source_fingerprint', $fingerprint)->update([
                    'hls_directory' => $directory, 'hls_manifest_key' => $directory.'/master.m3u8', 'duration_seconds' => (int) ceil($duration),
                    'renditions' => json_encode($renditions), 'processing_status' => 'ready', 'processing_error' => null, 'processed_at' => now(),
                ]);
                $current->forceFill(['encryption_type' => 'hls-aes128', 'encryption_status' => 'done'])->saveQuietly();

                return true;
            });
            if ($published && preg_match('#^hls/'.$asset->id.'/[a-f0-9-]{36}$#D', $previousDirectory ?? '') && $previousDirectory !== $directory) {
                $disk->deleteDirectory($previousDirectory);
            }
        } catch (Throwable $error) {
            VideoAsset::where('content_asset_id', $asset->id)->where('source_fingerprint', $fingerprint)
                ->update(['processing_status' => 'failed', 'processing_error' => 'HLS_PROCESSING_FAILED']);
            ContentAsset::whereKey($asset->id)->whereHas('videoAsset', fn ($q) => $q->where('source_fingerprint', $fingerprint))
                ->update(['encryption_status' => 'failed']);
            throw $error;
        } finally {
            if (! $published) {
                $disk->deleteDirectory($directory);
            }
            // The target is constructed here from a UUID, never from request input.
            $root = realpath(storage_path('app/private/hls-work'));
            $resolved = realpath($work);
            if ($root && $resolved && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($resolved);
            }
        }
    }

    private function probe(string $source): array
    {
        $process = new Process([config('media.ffprobe_binary'), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov,matroska,webm', '-show_streams', '-show_format', '-of', 'json', $source]);
        $process->setTimeout(30)->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
