<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Session extends Model
{
    public $timestamps = false;

    protected $fillable = ['teacher_id', 'availability_id', 'type', 'type_id', 'starts_at', 'ends_at', 'capacity', 'status', 'progress_notes'];

    protected static function booted(): void
    {
        static::saving(function (Session $session) {
            if (! in_array($session->type, ['individual', 'group'], true) || ! $session->type_id) {
                throw ValidationException::withMessages(['type_id' => 'A group or individual target is required.']);
            }
            $target = $session->type === 'group' ? Group::find($session->type_id) : Individual::find($session->type_id);
            if (! $target || (int) $target->teacherSubject?->teacher_id !== (int) $session->teacher_id) {
                throw ValidationException::withMessages(['type_id' => 'Session target must belong to the session teacher.']);
            }
            if ($session->type === 'individual') {
                $session->capacity = 1;
            }
            if ($session->isDirty('progress_notes')) {
                $session->progress_updated_at = now();
            }
        });
    }

    public function setStartsAtAttribute($value): void
    {
        $this->attributes['starts_at'] = $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public function setEndsAtAttribute($value): void
    {
        $this->attributes['ends_at'] = $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer', 'progress_updated_at' => 'datetime'];
    }

    public function target(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'type', 'type_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(TeacherAvailability::class, 'availability_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function paidBooking(): HasOne
    {
        return $this->hasOne(PaidBooking::class);
    }

    public function participantIds(): array
    {
        $target = $this->target;

        return $target instanceof Individual ? [(int) $target->student_id] : ($target?->groupStudents()->where('status', 'active')->pluck('student_id')->map(fn ($id) => (int) $id)->all() ?? []);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->forStudents([$studentId]);
    }

    public function scopeForStudents(Builder $query, array $ids): Builder
    {
        return $query->where(fn ($q) => $q->whereHasMorph('target', [Individual::class], fn ($q) => $q->whereIn('student_id', $ids))
            ->orWhereHasMorph('target', [Group::class], fn ($q) => $q->whereHas('groupStudents', fn ($q) => $q->where('status', 'active')->whereIn('student_id', $ids))));
    }
}
