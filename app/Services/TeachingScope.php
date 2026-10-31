<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

class TeachingScope
{
    public function resolve(TeacherSubject $course, string $type, int $id)
    {
        $item = match ($type) {
            'subject' => TeacherSubject::find($id), 'unit' => Unit::find($id), 'lesson' => Lesson::find($id), default => null
        };
        $courseId = match ($type) {
            'subject' => $item?->id,'unit' => $item?->teacher_subject_id,'lesson' => $item?->unit?->teacher_subject_id,default => null
        };
        if (! $item || (int) $courseId !== (int) $course->id) {
            throw ValidationException::withMessages(['teaching_scope_id' => 'الهدف المحدد لا يتبع مادة الأستاذ.']);
        }

        return $item;
    }

    public function payload($record): array
    {
        $type = $record->teaching_scope_type ?: 'subject';
        $id = $record->teaching_scope_id ?: $record->teacher_subject_id;
        $item = $this->resolve($record->teacherSubject, $type, $id);

        return ['type' => $type, 'id' => $id, 'title' => $type === 'subject' ? $item->subject?->name : $item->title];
    }
}
