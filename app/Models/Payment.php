<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    public $timestamps = false;

    protected $fillable = ['payer_id', 'payable_type', 'payable_id', 'amount', 'currency', 'provider', 'provider_reference', 'status', 'paid_at', 'internal_reference', 'sham_transfer_reference', 'proof_path', 'proof_mime', 'service_snapshot', 'rejection_reason', 'reviewed_by', 'reviewed_at', 'submitted_at'];

    protected $hidden = ['proof_path', 'sham_transfer_reference'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'service_snapshot' => 'array', 'reviewed_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function escrowTransactions(): HasMany
    {
        return $this->hasMany(EscrowTransaction::class);
    }
}
