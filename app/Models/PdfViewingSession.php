<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdfViewingSession extends Model
{
    protected $fillable = ['public_reference','user_id','document_id','token_hash','device_identifier','status','started_at','last_activity_at','expires_at','revoked_at'];
    protected $hidden = ['token_hash','device_identifier'];
    protected function casts(): array { return ['started_at'=>'datetime','last_activity_at'=>'datetime','expires_at'=>'datetime','revoked_at'=>'datetime']; }
    public function document(): BelongsTo { return $this->belongsTo(ContentAsset::class, 'document_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
