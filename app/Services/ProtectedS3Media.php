<?php

namespace App\Services;

use App\Models\ContentAsset;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProtectedS3Media
{
    public function response(Request $request, ContentAsset $asset, AwsS3V3Adapter $disk, array $headers): StreamedResponse
    {
        $size = $disk->size($asset->storage_key);
        $range = $request->header('Range');
        $start = 0;
        $end = $size - 1;
        $status = 200;
        if ($range !== null && $range !== '') {
            abort_unless(preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches)
                && ($matches[1] !== '' || $matches[2] !== ''), 416, 'Invalid byte range.', ['Content-Range' => 'bytes */'.$size]);
            if ($matches[1] === '') {
                $start = max(0, $size - (int) $matches[2]);
            } else {
                $start = (int) $matches[1];
                if ($matches[2] !== '') {
                    $end = min($end, (int) $matches[2]);
                }
            }
            abort_if($start > $end || $start >= $size, 416, 'Range is outside the file.', ['Content-Range' => 'bytes */'.$size]);
            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }
        $options = ['Bucket' => $disk->getConfig()['bucket'], 'Key' => $disk->path($asset->storage_key), '@http' => ['stream' => true]];
        if ($status === 206) {
            $options['Range'] = "bytes={$start}-{$end}";
        }
        $result = $disk->getClient()->getObject($options);
        $body = $result['Body'];
        $headers['Accept-Ranges'] = 'bytes';
        $headers['Content-Length'] = (string) max(0, $end - $start + 1);

        return response()->stream(function () use ($body) {
            try {
                while (! $body->eof()) {
                    echo $body->read(64 * 1024);
                }
            } finally {
                $body->close();
            }
        }, $status, $headers);
    }
}
