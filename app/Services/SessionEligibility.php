<?php

namespace App\Services;

use App\Models\Individual;
use App\Models\Session;
use Illuminate\Database\Eloquent\Builder;

class SessionEligibility
{
    public function individuals(int $studentId, Session $session): Builder
    {
        return Individual::whereDoesntHave('paidBookings')->where('student_id', $studentId)->where('status', 'active')
            ->whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $session->teacher_id)->where('status', 'active'))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $session->starts_at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $session->ends_at));
    }

    public function conflicts(int $studentId, Session $session): Builder
    {
        return Session::when($session->exists, fn ($q) => $q->whereKeyNot($session->id))->whereIn('status', ['scheduled', 'ongoing'])
            ->where('starts_at', '<', $session->ends_at)->where('ends_at', '>', $session->starts_at)->forStudent($studentId);
    }
}
