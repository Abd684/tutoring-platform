<?php

namespace App\Services;

use App\Models\ContentAsset;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class HlsPlayback
{
    public function ready(ContentAsset $asset): bool
    {
        $video = $asset->videoAsset;

        return $asset->status === 'active' && $asset->disk !== 'public'
            && $video?->processing_status === 'ready'
            && $video->source_fingerprint === app(HlsVideos::class)->fingerprint($asset)
            && preg_match('#^hls/'.$asset->id.'/[a-f0-9-]{36}$#D', $video->hls_directory ?? '')
            && $video->hls_manifest_key === $video->hls_directory.'/master.m3u8';
    }

    public function token(ContentAsset $asset, Student $student): array
    {
        $status = $asset->videoAsset?->processing_status ?? 'pending';
        if (! $this->ready($asset)) {
            abort(response()->json(['status' => false, 'code' => $status === 'failed' ? 'VIDEO_PROCESSING_FAILED' : 'VIDEO_PROCESSING',
                'message' => $status === 'failed' ? 'تعذرت معالجة الفيديو. يرجى طلب إعادة المعالجة من الأستاذ.' : 'الفيديو قيد التجهيز، حاول لاحقاً.',
                'data' => ['processing_status' => $status]], 409));
        }
        abort_unless(Storage::disk($asset->disk)->exists($asset->videoAsset->hls_manifest_key), 404);
        $expires = now()->addMinutes((int) config('media.hls_token_minutes', 30));

        return ['playback_url' => $this->url($asset, $student->id, 'master.m3u8', $expires), 'expires_at' => $expires->toIso8601String(),
            'format' => 'hls', 'mime_type' => 'application/vnd.apple.mpegurl', 'processing_status' => 'ready',
            'drm' => false, 'encryption' => 'aes-128', 'renditions' => $asset->videoAsset->renditions ?? []];
    }

    public function url(ContentAsset $asset, int $studentId, string $file, Carbon $expires): string
    {
        return URL::temporarySignedRoute('student.content.hls', $expires, ['contentAsset' => $asset->id, 'file' => $file,
            'student' => $studentId, 'version' => basename($asset->videoAsset->hls_directory)]);
    }

    public function allowedFile(string $file): bool
    {
        return (bool) preg_match('#^(master\.m3u8|[a-zA-Z0-9_-]+/(index\.m3u8|segment_[0-9]{6}\.ts|key\.bin))$#D', $file);
    }

    public function playlist(ContentAsset $asset, int $studentId, string $file, Carbon $expires): string
    {
        $text = Storage::disk($asset->disk)->get($asset->videoAsset->hls_directory.'/'.$file);
        abort_unless(is_string($text) && str_starts_with($text, '#EXTM3U'), 404);
        $base = dirname($file) === '.' ? '' : dirname($file).'/';
        $rewrite = function (string $uri) use ($asset, $studentId, $base, $expires) {
            $target = $base.$uri;
            abort_unless($this->allowedFile($target), 404);

            return $this->url($asset, $studentId, $target, $expires);
        };
        $lines = preg_split('/\r?\n/', $text);
        foreach ($lines as &$line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                $line = $rewrite($line);
            } elseif (str_contains($line, 'URI=')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', fn ($m) => 'URI="'.$rewrite($m[1]).'"', $line);
            }
        }

        return implode("\n", $lines);
    }
}
