<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;

class CurriculumVisibility
{
    public function teacher(?Teacher $teacher): bool
    {
        return $teacher?->status === 'active' && $teacher->user?->status === 'active';
    }

    public function course(?TeacherSubject $course): bool
    {
        return $course?->status === 'active' && $course->subject?->status === 'active'
            && $this->teacher($course->teacher);
    }

    public function unit(?Unit $unit): bool
    {
        return $unit?->status === 'published' && $this->course($unit->teacherSubject);
    }

    public function lesson(?Lesson $lesson): bool
    {
        return $lesson?->status === 'published' && $this->unit($lesson->unit);
    }
}
