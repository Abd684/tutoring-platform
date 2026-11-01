<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use Illuminate\Http\Request;

class TeacherGroupMembersController extends ApiController
{
    public function index(Request $r, Group $group)
    {
        $this->authorize('manage', $group);
        $data = $r->validate(['status' => ['sometimes', 'in:active,left,removed,all']]);
        $query = $group->groupStudents();
        if (isset($data['status']) && $data['status'] !== 'all') {
            $query->where('status', $data['status']);
        }
        $p = $query->with(['student.user:id,name,phone,region_id,avatar_path', 'student.school.region'])->orderByDesc('id')->paginate($this->perPage($r));

        return $this->paginated($p);
    }
}
