<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Services\HlsPlayback;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class HlsPlaybackController extends ApiController
{
    public function file(Request $request, ContentAsset $contentAsset, string $file, HlsPlayback $hls)
    {
        $this->authorize('accessStudent', Content::class);
        $student = $request->user()->student;
        abort_unless((int) $request->query('student') === (int) $student->id, 403);
        $this->authorize('access', $contentAsset->content);
        abort_unless(str_starts_with($contentAsset->mime_type ?? '', 'video/') && $hls->ready($contentAsset), 404);
        abort_unless($request->query('version') === basename($contentAsset->videoAsset->hls_directory) && $hls->allowedFile($file), 404);
        $disk = Storage::disk($contentAsset->disk);
        $key = $contentAsset->videoAsset->hls_directory.'/'.$file;
        abort_unless($disk->exists($key), 404);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];
        if (str_ends_with($file, '.m3u8')) {
            return response($hls->playlist($contentAsset, $student->id, $file, Carbon::createFromTimestamp((int) $request->query('expires'))))
                ->withHeaders($headers + ['Content-Type' => 'application/vnd.apple.mpegurl']);
        }
        $headers['Content-Type'] = str_ends_with($file, '/key.bin') ? 'application/octet-stream' : 'video/mp2t';
        if (config('filesystems.disks.'.$contentAsset->disk.'.driver') === 'local') {
            return response()->file($disk->path($key), $headers)->setContentDisposition('inline', basename($key))->setPrivate();
        }

        return $disk->response($key, null, $headers, 'inline')->setPrivate();
    }
}
