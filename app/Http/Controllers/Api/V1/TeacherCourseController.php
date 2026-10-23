<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Subject;
use App\Models\TeacherSubject;
use Illuminate\Http\Request;

class TeacherCourseController extends TeacherSubjectManagementController
{
    public function catalog(Request $request)
    {
        $this->authorize('viewOwnAny', TeacherSubject::class);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return $this->paginated(Subject::where('status', 'active')->select('id', 'name', 'description', 'status')->orderBy('id')->paginate($data['per_page'] ?? 20));
    }
}
