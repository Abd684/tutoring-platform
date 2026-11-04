<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContentCompletion;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Services\ContentAccess;
use App\Services\ListSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnlineLearningController extends ApiController
{
    private function visible(Lesson $lesson): void
    {
        $c = $lesson->unit?->teacherSubject;
        abort_unless($lesson->status === 'published' && $lesson->unit?->status === 'published' && $c?->status === 'active' && $c->subject?->status === 'active' && $c->teacher?->status === 'active' && $c->teacher?->user?->status === 'active', 404);
    }

    public function tree(Request $r, Teacher $teacher, ContentAccess $access)
    {
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        $courses = $teacher->teacherSubjects()->tap(fn ($query) => app(ListSearch::class)->apply($query, $r, 'curriculum'))->where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active'))->with(['subject', 'units' => fn ($q) => $q->where('status', 'published')->orderBy('order_no'), 'units.lessons' => fn ($q) => $q->where('status', 'published')->orderBy('order_no'), 'units.lessons.contents' => fn ($q) => $q->where('status', 'published')->orderBy('order_no')])->get();
        $rights = $access->accessibleContents($r->user()->student)->pluck('id')->flip();

        return $this->success(['teacher' => ['id' => $teacher->id, 'name' => $teacher->user->name, 'avatar_url' => $teacher->user->avatar_url, 'modes' => ['online', 'physical']],
            'subjects' => $courses->map(fn ($c) => ['teacher_subject_id' => $c->id, 'subject_id' => $c->subject_id, 'title' => $c->subject->name, 'price' => $c->price, 'currency' => 'SYP', 'units_count' => $c->units->count(), 'lessons_count' => $c->units->sum(fn ($u) => $u->lessons->count()),
                'units' => $c->units->map(fn ($u) => ['id' => $u->id, 'title' => $u->title, 'price' => $u->price, 'currency' => 'SYP', 'lessons_count' => $u->lessons->count(), 'lessons' => $u->lessons->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'price' => $l->price, 'currency' => 'SYP', 'contents_count' => $l->contents->count(), 'contents' => $l->contents->map(fn ($x) => $x->only(['id', 'type', 'title', 'price']) + ['has_access' => $rights->has($x->id)])])])])]);
    }

    public function content(Request $r, Lesson $lesson, ContentAccess $access)
    {
        $this->visible($lesson);
        $contents = $lesson->contents()->tap(fn ($query) => app(ListSearch::class)->apply($query, $r, 'contents'))->where('status', 'published')->with(['quizzes' => fn ($q) => $q->where('status', 'published')->select('id', 'content_id', 'quiz_type', 'attempts_allowed', 'duration_minutes', 'progress_weight', 'status')])->orderBy('order_no')->get();
        $rights = $access->accessibleContents($r->user()->student)->where('lesson_id', $lesson->id)->pluck('id')->flip();

        return $this->success(['lesson' => $lesson->only(['id', 'title', 'description', 'price']), 'access' => ['allowed' => $rights->isNotEmpty(), 'reason' => $rights->isNotEmpty() ? null : 'PAYMENT_REQUIRED'],
            'contents' => $contents->map(function ($c) use ($rights) {
                $allowed = $rights->has($c->id);

                return $c->only(['id', 'type', 'title', 'description']) + ['has_access' => $allowed, 'protected' => $c->type !== 'quiz' || $c->quizzes->contains(fn ($q) => $q->quiz_type === 'protected'),
                    'assets_endpoint' => $allowed ? url('/api/v1/contents/'.$c->id.'/assets') : null, 'playback_token_endpoint' => $allowed && $c->type === 'video' ? url('/api/v1/contents/'.$c->id.'/playback-token') : null,
                    'quizzes' => $c->quizzes->filter(fn ($q) => $allowed || $q->quiz_type === 'simple')->map(fn ($q) => $q->only(['id', 'quiz_type', 'attempts_allowed', 'duration_minutes', 'progress_weight']) + ['protected' => $q->quiz_type !== 'simple'])->values()];
            })]);
    }

    public function complete(Request $r, Lesson $lesson, ContentAccess $access)
    {
        return DB::transaction(function () use ($r, $lesson, $access) {
            Teacher::whereKey($lesson->unit->teacherSubject->teacher_id)->lockForUpdate()->firstOrFail();
            Student::whereKey($r->user()->student->id)->lockForUpdate()->firstOrFail();
            $this->visible($lesson);
            $required = $lesson->contents()->where('status', 'published')->where('type', '!=', 'quiz')->pluck('id');
            abort_if($required->isEmpty(), 422, 'هذا الدرس لا يحتوي محتوى تعليمياً قابلاً للإكمال.');
            abort_unless($access->accessibleContents($r->user()->student)->whereIn('id', $required)->count() === $required->count(), 404);
            $done = ContentCompletion::where('student_id', $r->user()->student->id)->whereIn('content_id', $required)->count();
            abort_if($done !== $required->count(), 422, 'أكمل محتويات الدرس أولاً.');
            $c = LessonCompletion::firstOrCreate(['student_id' => $r->user()->student->id, 'lesson_id' => $lesson->id], ['completed_at' => now()]);

            return $this->success(['lesson_id' => $lesson->id, 'completed' => true, 'completed_at' => $c->completed_at]);
        });
    }

    public function progress(Request $r, TeacherSubject $teacherSubject)
    {
        abort_unless($teacherSubject->status === 'active' && $teacherSubject->teacher?->status === 'active' && $teacherSubject->teacher?->user?->status === 'active', 404);
        $lessons = Lesson::whereHas('unit', fn ($q) => $q->where('teacher_subject_id', $teacherSubject->id)->where('status', 'published'))->where('status', 'published')->get();
        $done = LessonCompletion::where('student_id', $r->user()->student->id)->whereIn('lesson_id', $lessons->pluck('id'))->count();
        $quizzes = Quiz::where('status', 'published')->whereHas('content', fn ($q) => $q->where('status', 'published')->whereIn('lesson_id', $lessons->pluck('id')))->withSum('questions', 'points')->get();
        $points = 0;
        $completed = 0;
        foreach ($quizzes as $q) {
            $score = QuizAttempt::where('student_id', $r->user()->student->id)->where('quiz_id', $q->id)->where('status', 'graded')->max('score');
            if ($score !== null) {
                $completed++;
                $points += 0.30 * ($q->questions_sum_points > 0 ? min(1, max(0, (float) $score / (float) $q->questions_sum_points)) : 0);
            }
        }
        $total = $lessons->count() + 0.30 * $quizzes->count();

        return $this->success(['teacher_subject_id' => $teacherSubject->id, 'total_lessons' => $lessons->count(), 'completed_lessons' => $done, 'total_quizzes' => $quizzes->count(), 'completed_quizzes' => $completed, 'lesson_weight' => 1, 'quiz_weight' => 0.30, 'earned_progress_points' => round($done + $points, 4), 'total_progress_points' => $total, 'progress_percentage' => $total ? round(($done + $points) * 100 / $total, 2) : 0]);
    }
}
