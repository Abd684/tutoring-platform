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
        return $this->accessibleContents($student)->whereKey($content->id)->exists();
    }

    public function accessibleContents(Student $student): Builder
    {
        $query = Content::where('status', 'published');
        if ($student->status !== 'active' || $student->user?->status !== 'active') {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('lesson', fn ($q) => $q->where('status', 'published')->whereHas('unit', fn ($q) => $q->where('status', 'published')
            ->whereHas('teacherSubject', function ($q) use ($student) {
                $q->where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active'))
                    ->whereHas('teacher', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')))
                    ->whereExists($this->enrollments($student)->selectRaw('1')->whereColumn('teacher_id', 'teacher_subjects.teacher_id')
                        ->where(fn ($q) => $q
                            ->where(fn ($q) => $q->where('enrollable_type', 'content')->whereColumn('enrollable_id', 'contents.id'))
                            ->orWhere(fn ($q) => $q->where('enrollable_type', 'unit')->whereColumn('enrollable_id', 'units.id'))
                            ->orWhere(fn ($q) => $q->where('enrollable_type', 'teacher_subject')->whereColumn('enrollable_id', 'teacher_subjects.id'))));
            })));
    }

    public function allowsCourse(Student $student, TeacherSubject $course): bool
    {
        $units = Unit::where('teacher_subject_id', $course->id)->select('id');
        $contents = Content::whereHas('lesson.unit', fn ($q) => $q->where('teacher_subject_id', $course->id))->select('id');

        return $this->enrollments($student)->where('teacher_id', $course->teacher_id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('enrollable_type', 'teacher_subject')->where('enrollable_id', $course->id))
                ->orWhere(fn ($q) => $q->where('enrollable_type', 'unit')->whereIn('enrollable_id', $units))
                ->orWhere(fn ($q) => $q->where('enrollable_type', 'content')->whereIn('enrollable_id', $contents)))->exists();
    }

    public function published(TeacherSubject $course): Builder
    {
        return Content::where('status', 'published')->whereHas('lesson', fn ($q) => $q
            ->where('status', 'published')->whereHas('unit', fn ($q) => $q
            ->where('status', 'published')->where('teacher_subject_id', $course->id)));
    }
}
