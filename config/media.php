<?php

return [
    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    'hls_queue_connection' => env('HLS_QUEUE_CONNECTION', 'media'),
    'hls_queue' => 'media',
    'hls_process_timeout' => 900,
    'hls_segment_seconds' => 6,
    'hls_token_minutes' => 30,
    'hls_max_duration_seconds' => 14400,
    'hls_renditions' => [
        ['name' => '360p', 'width' => 640, 'height' => 360, 'bitrate' => 700],
        ['name' => '720p', 'width' => 1280, 'height' => 720, 'bitrate' => 1800],
        ['name' => '1080p', 'width' => 1920, 'height' => 1080, 'bitrate' => 3500],
    ],
];
