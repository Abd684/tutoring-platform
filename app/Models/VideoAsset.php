<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoAsset extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_asset_id', 'hls_manifest_key', 'duration_seconds', 'key_reference', 'watermark_enabled', 'processing_status', 'processing_error', 'hls_directory', 'source_fingerprint', 'renditions', 'processed_at'];

    protected $hidden = ['hls_manifest_key', 'hls_directory', 'source_fingerprint', 'key_reference'];

    protected function casts(): array
    {
        return ['watermark_enabled' => 'boolean', 'renditions' => 'array', 'processed_at' => 'datetime'];
    }

    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class);
    }
}
