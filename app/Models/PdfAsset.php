<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PdfAsset extends Model {
    public $timestamps = false;
    protected $fillable = ['content_asset_id','page_count','watermark_enabled','status'];
    protected function casts(): array { return ['watermark_enabled'=>'boolean']; }
    public function contentAsset(): BelongsTo { return $this->belongsTo(ContentAsset::class); }
}
