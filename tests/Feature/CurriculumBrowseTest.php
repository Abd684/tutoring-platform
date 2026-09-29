<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_curriculum_can_be_browsed_from_teacher_to_lesson_contents(): void
    {
        $user = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Mathematics', 'status' => 'active']);
        $teacherSubject = TeacherSubject::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'price' => 100,
            'status' => 'active',
        ]);
        $unit = Unit::create([
            'teacher_subject_id' => $teacherSubject->id,
            'title' => 'Algebra',
            'order_no' => 1,
            'status' => 'published',
        ]);
        $lesson = Lesson::create([
            'unit_id' => $unit->id,
            'title' => 'Linear equations',
            'order_no' => 1,
            'status' => 'published',
        ]);
        $content = Content::create([
            'lesson_id' => $lesson->id,
            'type' => 'video',
            'title' => 'Introduction',
            'order_no' => 1,
            'status' => 'published',
        ]);

        $this->getJson("/api/v1/teachers/{$teacher->id}/subjects")
            ->assertOk()
            ->assertJsonPath('data.0.id', $teacherSubject->id)
            ->assertJsonPath('data.0.subject.name', 'Mathematics');

        $this->getJson("/api/v1/teacher/subjects/{$teacherSubject->id}/units")
            ->assertOk()
            ->assertJsonPath('data.0.id', $unit->id);

        $this->getJson("/api/v1/units/{$unit->id}/lessons")
            ->assertOk()
            ->assertJsonPath('data.0.id', $lesson->id);

        $this->getJson("/api/v1/lessons/{$lesson->id}/contents")
            ->assertOk()
            ->assertJsonPath('data.0.id', $content->id)
            ->assertJsonPath('data.0.assets_count', 0)
            ->assertJsonPath('data.0.quizzes_count', 0);
    }

    public function test_drafts_are_not_exposed_by_browse_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Physics', 'status' => 'active']);
        TeacherSubject::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'price' => 50,
            'status' => 'inactive',
        ]);

        $this->getJson("/api/v1/teachers/{$teacher->id}/subjects")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
