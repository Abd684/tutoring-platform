<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
class WalletTransaction extends Model {
    public $timestamps = false;
    protected $fillable = ['wallet_id','type','amount','reference_type','reference_id','status'];
    protected function casts(): array { return ['amount'=>'decimal:2']; }
    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }
    public function reference(): MorphTo { return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id'); }
}
