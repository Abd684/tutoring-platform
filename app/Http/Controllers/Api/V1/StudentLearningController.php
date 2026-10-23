<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\ContentCompletion;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\ContentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudentLearningController extends ApiController
{
    public function complete(Request $request, Content $content, ContentAccess $access)
    {
        $this->authorize('accessStudent', Content::class);
        $this->authorize('access', $content);
        $course = $content->lesson->unit->teacherSubject;
        abort_unless($access->published($course)->whereKey($content->id)->exists(), 404);
        $completion = ContentCompletion::firstOrCreate(['student_id' => $request->user()->student->id, 'content_id' => $content->id], ['completed_at' => now()]);

        return $this->success([...$this->progressData($request, $course, $access), 'content_id' => $content->id, 'completed' => true, 'completed_at' => $completion->completed_at->toIso8601String()], 'Content completed successfully.');
    }

    public function progress(Request $request, TeacherSubject $teacherSubject, ContentAccess $access)
    {
        $this->authorize('accessStudent', Content::class);
        $this->requireCourseAccess($request, $teacherSubject, $access);

        return $this->success($this->progressData($request, $teacherSubject, $access));
    }

    private function requireCourseAccess(Request $request, TeacherSubject $course, ContentAccess $access): void
    {
        $this->authorize('accessLearning', $course);
    }

    private function progressData(Request $request, TeacherSubject $course, ContentAccess $access): array
    {
        $ids = $access->published($course)->pluck('id');
        $completed = ContentCompletion::where('student_id', $request->user()->student->id)
            ->whereIn('content_id', $ids)->orderBy('content_id')->pluck('content_id');

        return ['teacher_subject_id' => $course->id, 'total_contents' => $ids->count(),
            'completed_contents' => $completed->count(), 'completed_content_ids' => $completed,
            'progress_percentage' => $ids->isEmpty() ? 0 : round($completed->count() * 100 / $ids->count(), 2)];
    }

    public function course(Request $request, TeacherSubject $teacherSubject, ContentAccess $access)
    {
        $this->authorize('accessStudent', Content::class);
        abort_unless($teacherSubject->status === 'active' && $teacherSubject->subject?->status === 'active'
            && $teacherSubject->teacher?->status === 'active' && $teacherSubject->teacher?->user?->status === 'active', 404);
        $teacherSubject->load(['subject', 'units' => fn ($q) => $q->where('status', 'published')->orderBy('order_no'),
            'units.lessons' => fn ($q) => $q->where('status', 'published')->orderBy('order_no'),
            'units.lessons.contents' => fn ($q) => $q->where('status', 'published')->with('assets.videoAsset')->orderBy('order_no')]);
        $units = $teacherSubject->units;
        $lessons = $units->flatMap->lessons;
        $contents = $lessons->flatMap->contents;

        return $this->success(['id' => $teacherSubject->id, 'subject' => $teacherSubject->subject,
            'teacher_id' => $teacherSubject->teacher_id, 'price' => $teacherSubject->price,
            'units_count' => $units->count(), 'lessons_count' => $lessons->count(), 'contents_count' => $contents->count(),
            'progress' => $this->progressData($request, $teacherSubject, $access),
            'units' => $units->map(fn ($unit) => ['id' => $unit->id, 'title' => $unit->title, 'price' => $unit->price,
                'lessons' => $unit->lessons->map(fn ($lesson) => ['id' => $lesson->id, 'title' => $lesson->title,
                    'contents' => $lesson->contents->map(fn ($content) => [
                        ...$content->only(['id', 'type', 'title', 'description', 'learning_objectives', 'price']),
                        'duration_seconds' => $content->assets->where('status', 'active')->sortByDesc('id')->first(fn ($asset) => $asset->videoAsset !== null)?->videoAsset?->duration_seconds,
                        'has_access' => $access->allows($request->user()->student, $content),
                    ])])])]);
    }

    public function teacher(Teacher $teacher, ContentAccess $access)
    {
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        $students = $teacher->enrollments()->whereIn('status', ['active', 'expired'])->distinct()->count('student_id');
        // Rate across confirmed full-course enrollments and currently published content.
        $total = 0;
        $completed = 0;
        foreach ($teacher->teacherSubjects()->where('status', 'active')->get() as $course) {
            $ids = $access->published($course)->pluck('id');
            $studentIds = $teacher->enrollments()->where('enrollable_type', 'teacher_subject')->where('enrollable_id', $course->id)
                ->whereIn('status', ['active', 'expired'])->distinct()->pluck('student_id');
            $total += $ids->count() * $studentIds->count();
            $completed += ContentCompletion::whereIn('student_id', $studentIds)->whereIn('content_id', $ids)->count();
        }

        return $this->success(['id' => $teacher->id, 'name' => $teacher->user->name, 'avatar_url' => $teacher->user->avatar_url,
            'bio' => $teacher->bio, 'specialization' => $teacher->specialization, 'experience_years' => $teacher->experience_years,
            'students_count' => $students, 'completion_rate' => $total ? round($completed * 100 / $total, 2) : null]);
    }

    public function updateTeacher(Request $request)
    {
        $teacher = $request->user()->teacher;
        abort_unless($teacher && $teacher->status === 'active' && $request->user()->status === 'active', 403);
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)],
            'region_id' => ['sometimes', 'required', 'integer', 'exists:regions,id'],
            'experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        DB::transaction(function () use ($user, $teacher, $data) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedTeacher = Teacher::whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedUser->status === 'active' && $lockedTeacher->status === 'active', 403);
            $lockedUser->update(array_intersect_key($data, array_flip(['name', 'email', 'phone', 'region_id'])));
            $lockedTeacher->update(array_intersect_key($data, array_flip(['experience_years', 'bio', 'specialization'])));
        });

        $user->refresh();

        // Keep the existing teacher fields in data; user is an additive field.
        return $this->success($teacher->fresh()->load('user'));
    }
}
