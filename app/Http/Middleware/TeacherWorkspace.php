<?php

namespace App\Http\Middleware;

use App\Models\Group;
use App\Models\Session;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class TeacherWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->route('teacherUser');
        $user = $user instanceof User ? $user : User::findOrFail($user);
        abort_unless($user->role === 'teacher' && $user->teacher, 404);
        $request->route()->setParameter('teacherUser', $user);
        $group = $request->route('group');
        $session = $request->route('session');
        if ($group instanceof Group) {
            abort_unless((int) $group->teacherSubject?->teacher_id === $user->teacher->id, 404);
        }
        if ($session instanceof Session) {
            abort_unless((int) $session->teacher_id === $user->teacher->id, 404);
        }
        view()->share('workspaceTeacherUser', $user);

        return $next($request);
    }
}
