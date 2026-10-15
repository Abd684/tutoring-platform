<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Session;
use Illuminate\Database\Eloquent\Builder;

class SessionEligibility
{
    public function enrollments(int $studentId, Session $session): Builder
    {
        $query = Enrollment::query()->where('student_id', $studentId)
            ->where('teacher_id', $session->teacher_id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $session->ends_at));

        return $query;
    }

    public function conflicts(int $studentId, Session $session): Builder
    {
        return Session::query()->where('id', '!=', $session->id)
            ->whereIn('status', ['scheduled', 'ongoing'])
            ->where('starts_at', '<', $session->ends_at)->where('ends_at', '>', $session->starts_at)
            ->where(function ($q) use ($studentId) {
                $q->whereHas('enrollment', fn ($q) => $q->where('student_id', $studentId))
                    ->orWhereHas('group.groupEnrollments', fn ($q) => $q->where('status', 'active')
                        ->whereHas('enrollment', fn ($q) => $q->where('student_id', $studentId)));
            });
    }
}
