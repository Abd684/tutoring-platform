<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use Illuminate\Http\Request;

class TeacherGroupMembersController extends ApiController
{
    public function index(Request $r, Group $group)
    {
        $this->authorize('manage', $group);
        $p = $group->groupStudents()->with(['student.user:id,name,phone,region_id,avatar_path', 'student.school.region'])->orderByDesc('id')->paginate($this->perPage($r));

        return $this->paginated($p);
    }
}
