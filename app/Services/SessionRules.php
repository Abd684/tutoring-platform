<?php

namespace App\Services;

use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Validation\ValidationException;

class SessionRules
{
    /** Validate the merged final state, including partial updates. Caller holds the teacher lock. */
    public function validate(Session $session): void
    {
        $fail = fn ($field, $message) => throw ValidationException::withMessages([$field => $message]);
        if ($session->type === 'GROUP') {
            if (! $session->group_id || $session->enrollment_id) {
                $fail('group_id', 'Group sessions require a group and no individual enrollment.');
            }
            $group = $session->group()->with('teacherSubject')->first();
            if (! $group || (int) $group->teacherSubject?->teacher_id !== (int) $session->teacher_id || $group->status === 'closed') {
                $fail('group_id', 'An open group belonging to this teacher is required.');
            }
        } elseif ($session->type === 'INDIVIDUAL') {
            if (! $session->enrollment_id || $session->group_id) {
                $fail('enrollment_id', 'Individual sessions require an enrollment and no group.');
            }
            $enrollment = $session->enrollment()->first();
            if (! $enrollment || ! app(SessionEligibility::class)->enrollments($enrollment->student_id, $session)->whereKey($enrollment->id)->exists()) {
                $fail('enrollment_id', 'An eligible active enrollment for this teacher is required.');
            }
            $session->capacity = 1;
        } else {
            $fail('type', 'Invalid session type.');
        }
        if (! $session->starts_at || ! $session->ends_at || $session->ends_at->lte($session->starts_at)) {
            $fail('ends_at', 'End time must be after start time.');
        }
        $teacher = Teacher::with('user')->findOrFail($session->teacher_id);
        if ($teacher->status !== 'active' || $teacher->user?->status !== 'active') {
            $fail('teacher_id', 'The teacher is not active.');
        }
        if (! in_array($session->status, ['scheduled', 'ongoing'], true)) {
            return;
        }
        if (Session::where('teacher_id', $session->teacher_id)->when($session->exists, fn ($q) => $q->whereKeyNot($session->id))
            ->whereIn('status', ['scheduled', 'ongoing'])->where('starts_at', '<', $session->ends_at)
            ->where('ends_at', '>', $session->starts_at)->lockForUpdate()->first() !== null) {
            $fail('starts_at', 'The teacher has an overlapping session.');
        }
        $studentIds = $session->type === 'INDIVIDUAL'
            ? [$session->enrollment()->firstOrFail()->student_id]
            : $session->group->groupEnrollments()->where('status', 'active')->with('enrollment')->get()->pluck('enrollment.student_id')->filter()->unique();
        Student::whereIn('id', $studentIds)->orderBy('id')->lockForUpdate()->get();
        foreach ($studentIds as $studentId) {
            if (app(SessionEligibility::class)->conflicts($studentId, $session)->lockForUpdate()->first() !== null) {
                $fail('starts_at', 'A participating student has an overlapping session.');
            }
        }
    }
}
