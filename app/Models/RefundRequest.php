<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['active_payment_id', 'refund_proof_path', 'original_proof_path'];

    protected function casts(): array
    {
        return ['payout_details_required' => 'boolean', 'refund_transferred_at' => 'datetime', 'original_transfer_date' => 'datetime', 'amount' => 'decimal:2', 'reviewed_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
