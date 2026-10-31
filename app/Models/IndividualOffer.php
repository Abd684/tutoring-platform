<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndividualOffer extends Model
{
    protected $fillable = ['teacher_subject_id', 'teaching_scope_type', 'teaching_scope_id', 'title', 'session_price', 'currency', 'status'];

    protected function casts(): array
    {
        return ['session_price' => 'decimal:2'];
    }

    public function teacherSubject(): BelongsTo
    {
        return $this->belongsTo(TeacherSubject::class);
    }

    public function individuals(): HasMany
    {
        return $this->hasMany(Individual::class);
    }
}
