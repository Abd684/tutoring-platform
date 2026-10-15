<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/** Generic CRUD is administrative; owner workflows use explicit abilities. */
abstract class ResourcePolicy
{
    protected ?string $ownerPath = null;

    protected ?string $teacherPath = null;

    protected function active(User $user): bool
    {
        return $user->status === 'active';
    }

    protected function administrator(User $user): bool
    {
        return $this->active($user) && $user->role === 'company_admin';
    }

    protected function teacher(User $user): bool
    {
        return $this->active($user) && $user->role === 'teacher'
            && $user->teacher?->status === 'active';
    }

    public function viewAny(User $user): bool
    {
        return $this->administrator($user);
    }

    public function viewOwnAny(User $user): bool
    {
        return $this->teacher($user) || ($this->active($user)
            && $user->role === 'student' && $user->student?->status === 'active');
    }

    public function accessStudent(User $user): bool
    {
        return $this->active($user) && $user->role === 'student'
            && $user->student?->status === 'active';
    }

    public function view(User $user, Model $record): Response
    {
        if ($this->administrator($user)) {
            return Response::allow();
        }

        if ($this->manage($user, $record)->allowed()) {
            return Response::allow();
        }

        $ownerId = $this->ownerPath === null ? null : data_get($record, $this->ownerPath);
        $owns = $ownerId !== null && (int) $ownerId === (int) $user->id;

        $activeProfile = match ($user->role) {
            'student' => $user->student?->status === 'active',
            'teacher' => $user->teacher?->status === 'active',
            default => false,
        };

        return $this->active($user) && $activeProfile && $owns
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $this->administrator($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->administrator($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->administrator($user);
    }

    public function manage(User $user, Model $record): Response
    {
        $teacherId = $this->teacherPath === null ? null : data_get($record, $this->teacherPath);

        return $this->teacher($user) && $teacherId !== null
            && (int) $teacherId === (int) $user->teacher->id
                ? Response::allow() : Response::denyAsNotFound();
    }

    public function upload(User $user, Model $record): Response
    {
        return $this->manage($user, $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }
}
