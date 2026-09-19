<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class VideoAsset extends Model {
    public $timestamps = false;
    protected $fillable = ['content_asset_id','hls_manifest_key','duration_seconds','key_reference','watermark_enabled'];
    protected function casts(): array { return ['watermark_enabled'=>'boolean']; }
    public function contentAsset(): BelongsTo { return $this->belongsTo(ContentAsset::class); }
}
