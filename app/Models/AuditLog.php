<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
class AuditLog extends Model {
    public $timestamps = false;
    protected $fillable = ['user_id','action','entity_type','entity_id','metadata_json','ip','created_at'];
    protected function casts(): array { return ['metadata_json'=>'array','created_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function entity(): MorphTo { return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id'); }
}
