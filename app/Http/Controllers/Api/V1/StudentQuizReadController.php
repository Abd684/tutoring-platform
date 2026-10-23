<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\ContentAccess;
use Illuminate\Http\Request;

class StudentQuizReadController extends ApiController
{
    private function pending($query): void
    {
        $query->where(fn ($q) => $q->where('status', 'pending')->orWhereNull('score'));
    }

    private function attemptPayload(QuizAttempt $attempt, Quiz $quiz): array
    {
        $pending = $attempt->status === 'submitted' && ($attempt->score === null || $attempt->pending_answers_count > 0);
        $expired = $attempt->status === 'in_progress' && $quiz->duration_minutes !== null && $attempt->started_at
            && $attempt->started_at->copy()->addMinutes((int) $quiz->duration_minutes)->lte(now());

        return ['attempt_id' => $attempt->id, 'status' => $attempt->status, 'started_at' => $attempt->started_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(), 'score' => $pending || $attempt->score === null ? null : (float) $attempt->score,
            'grading_pending' => $pending, 'time_expired' => (bool) $expired];
    }

    public function index(Request $request, ContentAccess $access)
    {
        $this->authorize('accessStudent', Quiz::class);
        $student = $request->user()->student;
        $data = $request->validate(['scope' => ['sometimes', 'in:available,history,all'],
            'status' => ['sometimes', 'in:not_started,in_progress,submitted,graded,pending_grading'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $scope = $data['scope'] ?? 'all';
        $available = fn ($q) => $q->where('status', 'published')->whereIn('content_id', $access->accessibleContents($student)->select('contents.id'));
        $owned = fn ($q) => $q->where('student_id', $student->id);
        $query = Quiz::query()->where(function ($q) use ($scope, $available, $owned) {
            if ($scope === 'available') {
                $available($q);
            } elseif ($scope === 'history') {
                $q->whereHas('attempts', $owned);
            } else {
                $q->where($available)->orWhereHas('attempts', $owned);
            }
        });
        if (isset($data['status'])) {
            if ($data['status'] === 'not_started') {
                $query->whereDoesntHave('attempts', $owned);
            } else {
                $query->whereHas('attempts', function ($q) use ($student, $data) {
                    $q->where('student_id', $student->id);
                    if ($data['status'] === 'pending_grading') {
                        $q->where('status', 'submitted')->where(fn ($q) => $q->whereNull('score')->orWhereHas('answers', fn ($q) => $this->pending($q)));
                    } else {
                        $q->where('status', $data['status']);
                    }
                });
            }
        }
        $query->with(['content.lesson.unit.teacherSubject.subject', 'content.lesson.unit.teacherSubject.teacher.user:id,name,avatar_path'])
            ->withCount(['attempts as attempts_used' => $owned])
            ->addSelect(['latest_attempt_id' => QuizAttempt::select('id')->whereColumn('quiz_id', 'quizzes.id')->where('student_id', $student->id)->orderByDesc('id')->limit(1),
                'active_attempt_id' => QuizAttempt::select('id')->whereColumn('quiz_id', 'quizzes.id')->where('student_id', $student->id)->where('status', 'in_progress')->orderByDesc('id')->limit(1)]);
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? 20);
        $ids = $page->getCollection()->flatMap(fn ($q) => [$q->latest_attempt_id, $q->active_attempt_id])->filter()->unique();
        $attempts = QuizAttempt::where('student_id', $student->id)->whereIn('id', $ids)->withCount(['answers as pending_answers_count' => fn ($q) => $this->pending($q)])->get()->keyBy('id');
        $allowed = $access->accessibleContents($student)->whereIn('id', $page->getCollection()->pluck('content_id'))->pluck('id')->flip();
        $page->setCollection($page->getCollection()->map(function ($quiz) use ($attempts, $allowed) {
            $content = $quiz->content;
            $course = $content?->lesson?->unit?->teacherSubject;
            $latest = $attempts->get($quiz->latest_attempt_id);
            $active = $attempts->get($quiz->active_attempt_id);
            $canAccess = $quiz->status === 'published' && $allowed->has($quiz->content_id);
            $activeData = $active ? $this->attemptPayload($active, $quiz) : null;

            return ['quiz_id' => $quiz->id, 'quiz_title' => $content?->title, 'quiz_status' => $quiz->status, 'content_id' => $quiz->content_id,
                'lesson_id' => $content?->lesson_id, 'teacher_subject_id' => $course?->id, 'subject_name' => $course?->subject?->name,
                'teacher' => $course?->teacher ? ['id' => $course->teacher->id, 'name' => $course->teacher->user?->name, 'avatar_url' => $course->teacher->user?->avatar_url] : null,
                'attempts_allowed' => (int) $quiz->attempts_allowed, 'attempts_used' => (int) $quiz->attempts_used,
                'attempts_remaining' => max(0, (int) $quiz->attempts_allowed - (int) $quiz->attempts_used),
                'can_access' => $canAccess, 'can_start' => $canAccess && (int) $quiz->attempts_used < (int) $quiz->attempts_allowed,
                'active_attempt' => $activeData, 'can_resume' => $canAccess && $activeData !== null && ! $activeData['time_expired'],
                'latest_attempt' => $latest ? $this->attemptPayload($latest, $quiz) : null,
                'attempts_endpoint' => '/student/my-quizzes/'.$quiz->id.'/attempts'];
        }));

        return $this->paginated($page);
    }

    public function attempts(Request $request, Quiz $quiz, ContentAccess $access)
    {
        $this->authorize('accessStudent', Quiz::class);
        $student = $request->user()->student;
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $own = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $student->id);
        $canAccess = $quiz->status === 'published' && $quiz->content && $access->allows($student, $quiz->content);
        abort_unless($canAccess || (clone $own)->exists(), 404);
        $page = $own->withCount(['answers as pending_answers_count' => fn ($q) => $this->pending($q)])->orderByDesc('id')->paginate($data['per_page'] ?? 20);
        $page->setCollection($page->getCollection()->map(fn ($attempt) => $this->attemptPayload($attempt,$quiz)));

        return $this->paginated($page);
    }
}
