<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class PaidBooking extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'hold_expires_at' => 'datetime'];
    }

    public function scopeHolding(Builder $query): Builder
    {
        return $query->where('status', 'HELD')->where('hold_expires_at', '>', now());
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(TeacherAvailability::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function enrollment(): MorphOne
    {
        return $this->morphOne(Enrollment::class, 'enrollable');
    }
}
