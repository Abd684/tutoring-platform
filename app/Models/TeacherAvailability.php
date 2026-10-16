<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class TeacherAvailability extends Model
{
    protected static function booted(): void
    {
        static::saving(function (TeacherAvailability $availability): void {
            if (($availability->recurrence_type === 'weekly' && $availability->weekday === null)
                || ($availability->recurrence_type !== 'weekly' && $availability->date === null)) {
                throw ValidationException::withMessages(['date' => 'Weekly availability requires weekday; other availability requires date.']);
            }
        });
    }

    public $timestamps = false;

    protected $fillable = ['teacher_id', 'weekday', 'date', 'start_time', 'end_time', 'recurrence_type', 'timezone', 'status'];

    protected function casts(): array
    {
        return ['date' => 'date', 'weekday' => 'integer'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'availability_id');
    }
}
