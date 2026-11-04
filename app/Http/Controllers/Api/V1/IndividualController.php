<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Individual;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Services\ListSearch;
use App\Services\TeachingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IndividualController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorize('viewOwnAny', Individual::class);

        return $this->paginated(Individual::whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $request->user()->teacher->id))
            ->with(['student.user:id,name', 'teacherSubject.subject'])->orderByDesc('id')->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'individuals'))->paginate($this->perPage($request)));
    }

    public function store(Request $request)
    {
        $this->authorize('viewOwnAny', Individual::class);
        $data = $request->validate(['student_id' => ['required', 'integer', 'exists:students,id'], 'teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'],
            'teaching_scope_type' => ['sometimes', 'in:subject,unit,lesson'], 'teaching_scope_id' => ['required_with:teaching_scope_type', 'integer'], 'session_price' => ['sometimes', 'numeric', 'between:0,9999999999.99'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => array_merge(['nullable', 'date'], $request->filled('starts_at') ? ['after:starts_at'] : []), 'notes' => ['nullable', 'string', 'max:10000']]);

        return DB::transaction(function () use ($data, $request) {
            Teacher::whereKey($request->user()->teacher->id)->lockForUpdate()->firstOrFail();
            $course = TeacherSubject::where('teacher_id', $request->user()->teacher->id)->findOrFail($data['teacher_subject_id']);
            abort_unless($course->status === 'active', 422, 'Subject must be active.');
            app(TeachingScope::class)->resolve($course, $data['teaching_scope_type'] ?? 'subject', $data['teaching_scope_id'] ?? $course->id);
            $student = Student::whereKey($data['student_id'])->lockForUpdate()->firstOrFail();
            abort_unless($student->status === 'active' && $student->user?->status === 'active', 422, 'Student must be active.');

            return $this->success(Individual::create($data + ['status' => 'active', 'session_price' => $course->individual_session_price ?? 0, 'currency' => $course->individual_currency]), 'Individual teaching created.', 201);
        });
    }

    public function show(Individual $individual)
    {
        $this->authorize('manage', $individual);

        return $this->success($individual->load(['student.user:id,name', 'teacherSubject.subject', 'sessions']));
    }

    public function update(Request $request, Individual $individual)
    {
        $this->authorize('manage', $individual);
        $data = $request->validate(['student_id' => ['prohibited'], 'teacher_subject_id' => ['prohibited'],
            'status' => ['sometimes', 'in:active,completed,cancelled'], 'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'], 'notes' => ['sometimes', 'nullable', 'string', 'max:10000']]);

        return DB::transaction(function () use ($individual, $data) {
            Teacher::whereKey($individual->teacherSubject->teacher_id)->lockForUpdate()->firstOrFail();
            $individual = Individual::whereKey($individual->id)->lockForUpdate()->firstOrFail();
            $individual->fill($data);
            abort_if($individual->starts_at && $individual->ends_at && $individual->ends_at->lte($individual->starts_at), 422, 'End must follow start.');
            $future = $individual->sessions()->whereIn('status', ['scheduled', 'ongoing'])->get();
            foreach ($future as $session) {
                abort_if($individual->status !== 'active' || ($individual->starts_at && $individual->starts_at->gt($session->starts_at))
                    || ($individual->ends_at && $individual->ends_at->lt($session->ends_at)), 409, 'Cancel or reschedule existing sessions first.');
            }
            $individual->save();

            return $this->success($individual->fresh());
        });
    }
}
