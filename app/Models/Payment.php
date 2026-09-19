<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Payment extends Model {
    public $timestamps = false;
    protected $fillable = ['payer_id','payable_type','payable_id','amount','currency','provider','provider_reference','status','paid_at'];
    protected function casts(): array { return ['amount'=>'decimal:2','paid_at'=>'datetime']; }
    public function payer(): BelongsTo { return $this->belongsTo(User::class, 'payer_id'); }
    public function payable(): MorphTo { return $this->morphTo(); }
    public function escrowTransactions(): HasMany { return $this->hasMany(EscrowTransaction::class); }
}
