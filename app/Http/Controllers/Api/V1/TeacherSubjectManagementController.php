<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeacherSubjectManagementController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewOwnAny', TeacherSubject::class);

        return $this->paginated(TeacherSubject::query()
            ->where('teacher_id', $request->user()->teacher->id)
            ->with('subject:id,name,status')
            ->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('createOwn', TeacherSubject::class);

        $teacherId = $request->user()->teacher->id;
        $validated = $request->validate($this->rules($teacherId));
        $validated['teacher_id'] = $teacherId;
        if (! ($validated['group_enabled'] ?? false)) {
            $validated['max_group_size'] = null;
        }

        $course = DB::transaction(function () use ($teacherId, $validated) {
            $teacher = Teacher::whereKey($teacherId)->lockForUpdate()->firstOrFail();
            abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 403);
            if (TeacherSubject::where('teacher_id', $teacherId)->where('subject_id', $validated['subject_id'])->exists()) {
                throw ValidationException::withMessages(['subject_id' => ['This subject is already attached to your teacher account.']]);
            }
            $subject = Subject::whereKey($validated['subject_id'])->lockForUpdate()->firstOrFail();
            abort_unless($subject->status === 'active', 422, 'Subject is not active.');

            return TeacherSubject::create($validated);
        });

        return $this->success($course->fresh()->load('subject:id,name,status'), 'Teacher subject created successfully.', 201);
    }

    public function show(TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('manage', $teacherSubject);

        return $this->success($teacherSubject->load('subject:id,name,status'));
    }

    public function update(Request $request, TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('manage', $teacherSubject);

        $validated = $request->validate($this->rules($request->user()->teacher->id, $teacherSubject));
        if (! ($validated['group_enabled'] ?? $teacherSubject->group_enabled)) {
            $validated['max_group_size'] = null;
        }
        $teacherSubject->update($validated);

        return $this->success($teacherSubject->fresh()->load('subject:id,name,status'), 'Teacher subject updated successfully.');
    }

    public function destroy(TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('manage', $teacherSubject);
        $teacherSubject->delete();

        return $this->success(message: 'Teacher subject deleted successfully.');
    }

    private function rules(int $teacherId, ?TeacherSubject $course = null): array
    {
        $unique = Rule::unique('teacher_subjects', 'subject_id')
            ->where(fn ($query) => $query->where('teacher_id', $teacherId));
        if ($course !== null) {
            $unique->ignore($course->id);
        }

        return [
            'subject_id' => [$course === null ? 'required' : 'sometimes', 'integer', Rule::exists('subjects', 'id')->where('status', 'active'), $unique],
            'price' => [$course === null ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'group_enabled' => ['sometimes', 'boolean'],
            'max_group_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'teacher_id' => ['prohibited'],
            'status' => $course === null ? ['prohibited'] : ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
