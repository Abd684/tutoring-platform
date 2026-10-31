<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

class TeacherSubject extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (TeacherSubject $course) {
            if ($course->individuals()->exists() || $course->groups()->whereHas('sessions')->exists() || $course->groups()->whereHas('groupStudents')->exists()) {
                throw ValidationException::withMessages(['teacher_subject' => 'Subjects with teaching history must be retained.']);
            }
        });
    }

    public $timestamps = false;

    protected $fillable = ['teacher_id', 'subject_id', 'price', 'group_enabled', 'max_group_size', 'status'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'group_enabled' => 'boolean', 'max_group_size' => 'integer'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function enrollments(): MorphMany
    {
        return $this->morphMany(Enrollment::class, 'enrollable');
    }

    public function individuals(): HasMany
    {
        return $this->hasMany(Individual::class);
    }
}
