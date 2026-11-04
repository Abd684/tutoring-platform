<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
class ContentAsset extends Model {
    public $timestamps = false;
    protected $fillable = ['content_id','disk','storage_key','mime_type','file_size','checksum','encryption_type','encryption_status','status','processing_status','total_pages','processed_at','processing_error_code','processing_version'];
    protected function casts(): array { return ['processed_at' => 'datetime']; }
    public function content(): BelongsTo { return $this->belongsTo(Content::class); }
    public function videoAsset(): HasOne { return $this->hasOne(VideoAsset::class); }
    public function pdfAsset(): HasOne { return $this->hasOne(PdfAsset::class); }
}
