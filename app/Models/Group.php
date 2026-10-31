<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

class Group extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (Group $group) {
            if ($group->sessions()->exists() || $group->groupStudents()->exists()) {
                throw ValidationException::withMessages(['group' => 'Groups with teaching history must be closed instead of deleted.']);
            }
        });
        static::updating(function (Group $group) {
            if ($group->isDirty(['teacher_subject_id', 'teaching_scope_type', 'teaching_scope_id', 'session_price', 'currency']) && ($group->sessions()->exists() || $group->groupStudents()->exists())) {
                throw ValidationException::withMessages(['teacher_subject_id' => 'A group with teaching history cannot change subject.']);
            }
        });
    }

    public $timestamps = false;

    protected $fillable = ['teacher_subject_id', 'teaching_scope_type', 'teaching_scope_id', 'session_price', 'currency', 'name', 'capacity', 'status', 'starts_at', 'ends_at'];

    public function teacherSubject(): BelongsTo
    {
        return $this->belongsTo(TeacherSubject::class);
    }

    public function groupStudents(): HasMany
    {
        return $this->hasMany(GroupStudent::class);
    }

    public function sessions(): MorphMany
    {
        return $this->morphMany(Session::class, 'target', 'type', 'type_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'group_students')->withPivot(['status', 'joined_at', 'left_at']);
    }

    protected function casts(): array
    {
        return ['session_price' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
