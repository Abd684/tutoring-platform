<?php

namespace App\Policies;

use App\Models\Session;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SessionPolicy
{
    /**
     * Teacher can view his sessions list.
     */
    public function viewAny(User $user): bool
    {
        return $this->isActiveTeacher($user);
    }

    /**
     * Teacher can view only his own session.
     */
    public function view(
        User $user,
        Session $session
    ): Response {
        return $this->owns($user, $session);
    }

    /**
     * Teacher can create sessions.
     */
    public function create(User $user): bool
    {
        return $this->isActiveTeacher($user);
    }

    /**
     * Teacher can update only his own session.
     */
    public function update(
        User $user,
        Session $session
    ): Response {
        return $this->owns($user, $session);
    }

    /**
     * Teacher can delete only his own session.
     */
    public function delete(
        User $user,
        Session $session
    ): Response {
        return $this->owns($user, $session);
    }

    /**
     * Teacher can cancel only his own session.
     */
    public function cancel(
        User $user,
        Session $session
    ): Response {
        return $this->owns($user, $session);
    }

    /**
     * Not used currently.
     */
    public function restore(
        User $user,
        Session $session
    ): bool {
        return false;
    }

    /**
     * Not used currently.
     */
    public function forceDelete(
        User $user,
        Session $session
    ): bool {
        return false;
    }

    /**
     * Check that the session belongs to
     * the authenticated teacher.
     */
    private function owns(
        User $user,
        Session $session
    ): Response {
        return $this->isActiveTeacher($user) && (int) $session->teacher_id
            === (int) $user->teacher?->id
                ? Response::allow()
                : Response::denyAsNotFound();
    }

    public function recordAttendance(User $user, Session $session): Response
    {
        return $this->owns($user, $session);
    }

    public function book(User $user): bool
    {
        return $user->status === 'active' && $user->role === 'student'
            && $user->student?->status === 'active';
    }

    public function viewBooking(User $user, Session $session): Response
    {
        $studentId = $user->student?->id;
        $owns = $this->book($user) && ((int) $session->enrollment?->student_id === (int) $studentId
            || $session->group?->groupEnrollments()->where('status', 'active')->whereHas('enrollment', fn ($q) => $q->where('student_id', $studentId))->exists());

        return $owns ? Response::allow() : Response::denyAsNotFound();
    }

    public function cancelBooking(User $user, ?Session $session = null): bool
    {
        return $this->book($user) && ($session === null || (int) $session->enrollment?->student_id === (int) $user->student->id);
    }

    private function isActiveTeacher(User $user): bool
    {
        return $user->status === 'active' && $user->role === 'teacher'
            && $user->teacher?->status === 'active';
    }

    public function studentView(User $user, Session $session): Response
    {
        $studentId = $user->student?->id;
        $owns = $studentId && ((int) $session->enrollment?->student_id === (int) $studentId
            || $session->group?->groupEnrollments()->where('status', 'active')->whereHas('enrollment', fn ($q) => $q->where('student_id', $studentId))->exists());

        return $this->book($user) && $owns ? Response::allow() : Response::denyAsNotFound();
    }
}
