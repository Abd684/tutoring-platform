<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Read-only archive retained for historical bookings and migration rollback. */
class IndividualOffer extends Model
{
    protected static function booted(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(fn () => throw new \LogicException('Individual offers are archived and read-only.'));
        }
    }

    protected $fillable = ['teacher_subject_id', 'teaching_scope_type', 'teaching_scope_id', 'title', 'session_price', 'currency', 'status'];

    protected function casts(): array
    {
        return ['session_price' => 'decimal:2'];
    }

    public function teacherSubject(): BelongsTo
    {
        return $this->belongsTo(TeacherSubject::class);
    }

    public function legacyIndividuals(): HasMany
    {
        return $this->hasMany(Individual::class, 'legacy_individual_offer_id');
    }
}
