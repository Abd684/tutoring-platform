<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

class ContentAccess
{
    public function enrollments(Student $student): Builder
    {
        return Enrollment::where('student_id', $student->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function allows(Student $student, Content $content): bool
    {
        $content->loadMissing('lesson.unit.teacherSubject');
        $unit = $content->lesson?->unit;
        $course = $unit?->teacherSubject;
        if (! $course || $student->status !== 'active' || $student->user?->status !== 'active'
            || $content->status !== 'published' || $content->lesson->status !== 'published' || $unit->status !== 'published'
            || $course->status !== 'active' || $course->subject?->status !== 'active'
            || $course->teacher?->status !== 'active' || $course->teacher?->user?->status !== 'active') {
            return false;
        }

        return $this->enrollments($student)->where('teacher_id', $course->teacher_id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('enrollable_type', (new Content)->getMorphClass())->where('enrollable_id', $content->id))
                ->orWhere(fn ($q) => $q->where('enrollable_type', (new Unit)->getMorphClass())->where('enrollable_id', $unit->id))
                ->orWhere(fn ($q) => $q->where('enrollable_type', (new TeacherSubject)->getMorphClass())->where('enrollable_id', $course->id)))
            ->exists();
    }

    public function allowsCourse(Student $student, TeacherSubject $course): bool
    {
        $units = Unit::where('teacher_subject_id', $course->id)->select('id');
        $contents = Content::whereHas('lesson.unit', fn ($q) => $q->where('teacher_subject_id', $course->id))->select('id');

        return $this->enrollments($student)->where('teacher_id', $course->teacher_id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('enrollable_type', (new TeacherSubject)->getMorphClass())->where('enrollable_id', $course->id))
                ->orWhere(fn ($q) => $q->where('enrollable_type', (new Unit)->getMorphClass())->whereIn('enrollable_id', $units))
                ->orWhere(fn ($q) => $q->where('enrollable_type', (new Content)->getMorphClass())->whereIn('enrollable_id', $contents)))->exists();
    }

    public function published(TeacherSubject $course): Builder
    {
        return Content::where('status', 'published')->whereHas('lesson', fn ($q) => $q
            ->where('status', 'published')->whereHas('unit', fn ($q) => $q
            ->where('status', 'published')->where('teacher_subject_id', $course->id)));
    }
}
