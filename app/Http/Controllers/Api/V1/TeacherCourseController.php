<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeacherCourseController extends ApiController
{
    public function catalog(Request $request)
    {
        $this->authorize('viewOwnAny', TeacherSubject::class);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return $this->paginated(Subject::where('status', 'active')->select('id', 'name', 'description', 'status')->orderBy('id')->paginate($data['per_page'] ?? 20));
    }

    public function store(Request $request)
    {
        $this->authorize('viewOwnAny', TeacherSubject::class);
        $teacher = $request->user()->teacher;
        $data = $request->validate([
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->where('status', 'active')],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'group_enabled' => ['sometimes', 'boolean'],
            'max_group_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'teacher_id' => ['prohibited'], 'status' => ['prohibited'],
        ]);
        if (! ($data['group_enabled'] ?? false)) {
            $data['max_group_size'] = null;
        }
        $course = DB::transaction(function () use ($teacher, $data) {
            $lockedTeacher = Teacher::whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedTeacher->status === 'active' && $lockedTeacher->user?->status === 'active', 403);
            if (TeacherSubject::where('teacher_id', $teacher->id)->where('subject_id', $data['subject_id'])->exists()) {
                throw ValidationException::withMessages(['subject_id' => ['This subject is already attached to your teacher account.']]);
            }
            $subject = Subject::whereKey($data['subject_id'])->lockForUpdate()->firstOrFail();
            abort_unless($subject->status === 'active', 422, 'Subject is not active.');

            return TeacherSubject::create([...$data, 'teacher_id' => $teacher->id, 'status' => 'active']);
        });

        return $this->success($course->load('subject:id,name,description,status'), 'Teacher subject created successfully.', 201);
    }
}
