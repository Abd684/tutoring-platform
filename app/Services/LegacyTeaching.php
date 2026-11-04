<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Individual;
use App\Models\Session;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Adapter for already deployed clients. New code uses student_id and type/type_id. */
class LegacyTeaching
{
    public function enrollment(Group $group, int $id): Enrollment
    {
        $enrollment = Enrollment::with(['student.user', 'teacher'])->findOrFail($id);
        Gate::authorize('manage', $group);
        Gate::authorize('manage', $enrollment);
        if ($enrollment->status !== 'active' || $enrollment->teacher_id !== $group->teacherSubject->teacher_id
            || $enrollment->enrollable_type !== 'teacher_subject' || (int) $enrollment->enrollable_id !== (int) $group->teacher_subject_id
            || $enrollment->starts_at?->isFuture() || $enrollment->expires_at?->lte(now())) {
            throw ValidationException::withMessages(['enrollment_id' => 'A matching active full-subject enrollment is required.']);
        }

        return $enrollment;
    }

    public function adaptSession(Request $request): void
    {
        $teacher = $request->user()?->teacher;
        abort_unless($teacher, 403);
        Teacher::whereKey($teacher->id)->lockForUpdate()->firstOrFail();
        $data = $request->validate([
            'type' => ['sometimes', 'in:GROUP,INDIVIDUAL'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'enrollment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $type = strtolower($data['type'] ?? ($request->filled('group_id') ? 'group' : 'individual'));
        if ($type === 'group') {
            if ($request->filled('enrollment_id') || ! $request->filled('group_id')) {
                throw ValidationException::withMessages(['group_id' => 'A group session requires group_id and no enrollment_id.']);
            }
            $group = Group::findOrFail($data['group_id']);
            Gate::authorize('manage', $group);
            $target = $group->id;
        } else {
            if ($request->filled('group_id') || ! $request->filled('enrollment_id')) {
                throw ValidationException::withMessages(['enrollment_id' => 'An individual session requires enrollment_id and no group_id.']);
            }
            $enrollment = Enrollment::with('student.user')->lockForUpdate()->findOrFail($data['enrollment_id']);
            Gate::authorize('manage', $enrollment);
            abort_unless($enrollment->teacher_id === $teacher->id && $enrollment->status === 'active'
                && $enrollment->enrollable_type === 'teacher_subject' && ! $enrollment->starts_at?->isFuture()
                && ! $enrollment->expires_at?->lte(now()) && $enrollment->student?->status === 'active'
                && $enrollment->student?->user?->status === 'active', 422, 'A matching active enrollment is required.');
            $target = $this->individualForEnrollment($enrollment);
        }
        if ($request->filled('type_id') && (int) $request->input('type_id') !== (int) $target) {
            throw ValidationException::withMessages(['type_id' => 'Legacy and new targets disagree.']);
        }
        $request->request->remove('group_id');
        $request->request->remove('enrollment_id');
        if ($request->isJson()) {
            $request->json()->remove('group_id');
            $request->json()->remove('enrollment_id');
        }
        $request->merge(['type' => $type, 'type_id' => $target]);
    }

    private function individualForEnrollment(Enrollment $enrollment): int
    {
        $link = DB::table('legacy_teaching_links')->where('kind', 'individual')->where('source_id', $enrollment->id)->first();
        $id = $link ? json_decode($link->payload, true, 512, JSON_THROW_ON_ERROR)['individual_id'] : null;
        if (! $id) {
            $sources = DB::table('legacy_teaching_links')->where('kind', 'session')->get()->filter(fn ($row) => (int) (json_decode($row->payload, true)['enrollment_id'] ?? 0) === $enrollment->id)->pluck('source_id');
            $ids = Session::whereIn('id', $sources)->where('type', 'individual')->distinct()->pluck('type_id');
            abort_if($ids->count() > 1, 409, 'Legacy enrollment has multiple teaching targets; review is required.');
            $id = $ids->first();
        }
        if (! $id) {
            $course = $enrollment->enrollable;
            abort_unless($course && $course->teacher_id === $enrollment->teacher_id && $course->status === 'active', 422, 'Subject is unavailable.');
            $id = Individual::create(['student_id' => $enrollment->student_id, 'teacher_subject_id' => $course->id, 'status' => 'active', 'teaching_scope_type' => 'subject', 'teaching_scope_id' => $course->id])->id;
        }
        $individual = Individual::findOrFail($id);
        abort_unless($individual->student_id === $enrollment->student_id && $individual->teacher_subject_id === $enrollment->enrollable_id, 409, 'Legacy target mismatch.');
        DB::table('legacy_teaching_links')->updateOrInsert(['kind' => 'individual', 'source_id' => $enrollment->id], ['payload' => json_encode(['individual_id' => $id])]);

        return (int) $id;
    }
}
