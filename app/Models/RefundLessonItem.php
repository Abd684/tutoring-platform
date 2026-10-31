<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundLessonItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(PaymentLessonAllocation::class, 'payment_lesson_allocation_id');
    }
}
