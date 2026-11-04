<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

class Individual extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (Individual $individual) {
            if ($individual->sessions()->exists()) {
                throw ValidationException::withMessages(['individual' => 'Teaching with sessions must be retained.']);
            }
        });
    }

    protected $hidden = ['legacy_individual_offer_id', 'legacy_offer_snapshot'];

    protected $fillable = ['student_id', 'teacher_subject_id', 'teaching_scope_type', 'teaching_scope_id', 'session_price', 'currency', 'status', 'starts_at', 'ends_at', 'notes'];

    protected function casts(): array
    {
        return ['session_price' => 'decimal:2', 'legacy_offer_snapshot' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function paidBookings(): HasMany
    {
        return $this->hasMany(PaidBooking::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacherSubject(): BelongsTo
    {
        return $this->belongsTo(TeacherSubject::class);
    }

    public function sessions(): MorphMany
    {
        return $this->morphMany(Session::class, 'target', 'type', 'type_id');
    }
}
