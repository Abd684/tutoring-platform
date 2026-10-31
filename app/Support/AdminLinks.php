<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Session;

class AdminLinks
{
    public static function group(Group $group, string $action = 'show'): string
    {
        return route('admin.groups.'.$action, ['teacherUser' => $group->teacherSubject->teacher->user_id, 'group' => $group]);
    }

    public static function session(Session $session, string $action = 'show'): string
    {
        return route('admin.sessions.'.$action, ['teacherUser' => $session->teacher->user_id, 'session' => $session]);
    }
}
