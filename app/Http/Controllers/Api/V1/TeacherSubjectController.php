<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Teacher;
use App\Models\Enrollment;
use App\Models\TeacherSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherSubjectController extends ApiController
{
    /** Rank courses by distinct students whose confirmed enrollment started in the last three years. */
    public function mostRequested(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $to = now();
        $from = $to->copy()->subYearsNoOverflow(3);

        $counts = Enrollment::query()
            ->select('enrollable_id')
            ->selectRaw('COUNT(DISTINCT student_id) AS students_count')
            ->where('enrollable_type', (new TeacherSubject)->getMorphClass())
            ->whereIn('status', ['active', 'expired'])
            ->whereBetween('starts_at', [$from, $to])
            ->groupBy('enrollable_id');

        $courses = TeacherSubject::query()
            ->joinSub($counts, 'course_popularity', function ($join) {
                $join->on('teacher_subjects.id', '=', 'course_popularity.enrollable_id');
            })
            ->select('teacher_subjects.*', 'course_popularity.students_count')
            ->where('teacher_subjects.status', 'active')
            ->whereHas('subject', fn ($query) => $query->where('status', 'active'))
            ->whereHas('teacher', fn ($query) => $query->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('status', 'active')))
            ->with(['subject:id,name', 'teacher:id,user_id', 'teacher.user:id,name'])
            ->orderByDesc('course_popularity.students_count')
            ->orderBy('teacher_subjects.id')
            ->limit($validated['limit'] ?? 10)
            ->get()
            ->map(fn (TeacherSubject $course) => [
                'id' => $course->id,
                'name' => $course->subject->name,
                'teacher' => [
                    'id' => $course->teacher_id,
                    'name' => $course->teacher->user->name,
                ],
                'students_count' => (int) $course->students_count,
            ]);

        return response()->json([
            'status' => true,
            'data' => $courses,
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'limit' => (int) ($validated['limit'] ?? 10),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TeacherSubject::class);

        $query = TeacherSubject::query()
            ->with(['teacher.user:id,name,email', 'subject:id,name,status']);

        $query->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->has('group_enabled'), fn ($q) => $q->where('group_enabled', $request->boolean('group_enabled')));

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TeacherSubject::class);

        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'subject_id' => [
                'required', 'integer', 'exists:subjects,id',
                Rule::unique('teacher_subjects', 'subject_id')->where(fn ($q) => $q->where('teacher_id', $request->integer('teacher_id'))),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'group_enabled' => ['sometimes', 'boolean'],
            'max_group_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]);

        if (! ($validated['group_enabled'] ?? false)) {
            $validated['max_group_size'] = null;
        }

        $model = TeacherSubject::create($validated)->load(['teacher.user:id,name,email', 'subject:id,name,status']);

        return $this->success($model, 'Teacher subject created successfully.', 201);
    }

    public function show(TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('view', $teacherSubject);

        return $this->success($teacherSubject->load([
            'teacher.user:id,name,email',
            'subject:id,name,status',
        ])->loadCount(['units', 'groups']));
    }

    public function update(Request $request, TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('update', $teacherSubject);

        $teacherId = $request->integer('teacher_id', $teacherSubject->teacher_id);

        $validated = $request->validate([
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'subject_id' => [
                'sometimes', 'integer', 'exists:subjects,id',
                Rule::unique('teacher_subjects', 'subject_id')
                    ->where(fn ($q) => $q->where('teacher_id', $teacherId))
                    ->ignore($teacherSubject->id),
            ],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'group_enabled' => ['sometimes', 'boolean'],
            'max_group_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]);

        $effectiveGroupEnabled = array_key_exists('group_enabled', $validated)
            ? (bool) $validated['group_enabled']
            : (bool) $teacherSubject->group_enabled;

        if (! $effectiveGroupEnabled) {
            $validated['max_group_size'] = null;
        }

        $teacherSubject->update($validated);

        return $this->success($teacherSubject->fresh()->load(['teacher.user:id,name,email', 'subject:id,name,status']), 'Teacher subject updated successfully.');
    }

    public function destroy(TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('delete', $teacherSubject);

        $teacherSubject->delete();

        return $this->success(message: 'Teacher subject deleted successfully.');
    }

    public function getSubjectsByTeacher(Teacher $teacher): JsonResponse
    {
        $subjects = $teacher->teacherSubjects()
            ->where('status', 'active')
            ->whereHas('subject', fn ($query) => $query->where('status', 'active'))
            ->with(['subject:id,name,status'])
            ->orderBy('id')
            ->get();

        return $this->success($subjects, 'Subjects retrieved successfully.');
    }

    public function units(TeacherSubject $teacherSubject): JsonResponse
    {
        $units = $teacherSubject->units()
            ->where('status', 'published')
            ->orderBy('order_no')
            ->orderBy('id')
            ->get();

        return $this->success($units, 'Units retrieved successfully.');
    }
}
