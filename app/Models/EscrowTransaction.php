<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscrowTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'enrollment_id',
        'teacher_id',
        'gross_amount',
        'commission',
        'teacher_amount',
        'status',
        'release_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'commission' => 'decimal:2',
            'teacher_amount' => 'decimal:2',
            'release_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
