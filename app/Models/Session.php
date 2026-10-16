<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Session extends Model
{
    protected static function booted(): void
    {
        static::saving(function (Session $session): void {
            $valid = match ($session->type) {
                'GROUP' => $session->group_id !== null && $session->enrollment_id === null,
                'INDIVIDUAL' => $session->enrollment_id !== null && $session->group_id === null,
                default => false,
            };
            if (! $valid) {
                throw ValidationException::withMessages(['type' => 'Session type and enrollment/group links are inconsistent.']);
            }
            if ($session->type === 'INDIVIDUAL') {
                $session->capacity = 1;
            }
        });
    }

    public $timestamps = false;

    protected $fillable = [
        'teacher_id',
        'availability_id',
        'type',
        'group_id',
        'enrollment_id',
        'starts_at',
        'ends_at',
        'capacity',
        'status'];

    public function setStartsAtAttribute($value): void
    {
        $this->attributes['starts_at'] = $value === null ? null : Carbon::parse($value)
            ->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public function setEndsAtAttribute($value): void
    {
        $this->attributes['ends_at'] = $value === null ? null : Carbon::parse($value)
            ->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(TeacherAvailability::class, 'availability_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
