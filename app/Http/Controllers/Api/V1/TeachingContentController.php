<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Answer;
use App\Models\Content;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeachingContentController extends ApiController
{
    public function storeUnit(Request $r)
    {
        $d = $r->validate(['teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'], 'title' => ['required', 'string', 'max:255'], 'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'], 'order_no' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', 'in:draft,published,archived']]);
        $this->authorize('manage', TeacherSubject::findOrFail($d['teacher_subject_id']));

        return $this->success(Unit::create($d), 'تم إنشاء الوحدة.', 201);
    }

    public function updateUnit(Request $r, Unit $unit)
    {
        $this->authorize('manage', $unit);
        $d = $r->validate(['title' => ['sometimes', 'required', 'string', 'max:255'], 'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'], 'order_no' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', 'in:draft,published,archived'], 'teacher_subject_id' => ['prohibited']]);
        $unit->update($d);

        return $this->success($unit);
    }

    public function storeLesson(Request $r)
    {
        $d = $r->validate(['unit_id' => ['required', 'integer', 'exists:units,id'], 'title' => ['required', 'string', 'max:255'], 'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'], 'order_no' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', 'in:draft,published,archived']]);
        $this->authorize('manage', Unit::findOrFail($d['unit_id']));

        return $this->success(Lesson::create($d), 'تم إنشاء الدرس.', 201);
    }

    public function updateLesson(Request $r, Lesson $lesson)
    {
        $this->authorize('manage', $lesson);
        $d = $r->validate(['title' => ['sometimes', 'required', 'string', 'max:255'], 'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'], 'order_no' => ['sometimes', 'integer', 'min:0'], 'status' => ['sometimes', 'in:draft,published,archived'], 'unit_id' => ['prohibited']]);
        $lesson->update($d);

        return $this->success($lesson);
    }

    public function quiz(Request $r, Content $content)
    {
        $this->authorize('manage', $content);
        abort_unless($content->type === 'quiz', 422);
        $d = $r->validate(['quiz_type' => ['required', 'in:simple,protected'], 'attempts_allowed' => ['required', 'integer', 'between:1,100'], 'duration_minutes' => ['nullable', 'integer', 'between:1,1440'], 'status' => ['required', 'in:draft,published,archived'],
            'questions' => ['required', 'array', 'min:1', 'max:100'], 'questions.*.type' => ['required', 'in:mcq,true_false,essay'], 'questions.*.body' => ['required', 'string', 'max:10000'], 'questions.*.points' => ['required', 'numeric', 'between:0,1000'],
            'questions.*.rubric_json' => ['nullable', 'array'], 'questions.*.rubric_json.options' => ['sometimes', 'array'], 'questions.*.rubric_json.correct_answer' => ['sometimes'], 'questions.*.rubric_json.explanation' => ['sometimes', 'string']]);

        return DB::transaction(function () use ($content, $d) {
            $quiz = $content->quizzes()->lockForUpdate()->first();
            abort_if($quiz && $quiz->attempts()->exists(), 409, 'لا يمكن تغيير الاختبار بعد بدء المحاولات.');
            $quiz ??= new Quiz(['content_id' => $content->id]);
            $quiz->fill(array_intersect_key($d, array_flip(['quiz_type', 'attempts_allowed', 'duration_minutes', 'status'])));
            $quiz->progress_weight = 0.30;
            $quiz->save();
            $quiz->questions()->delete();
            foreach ($d['questions'] as $i => $q) {
                $q['order_no'] = $i;
                $quiz->questions()->create($q);
            }

            return $this->success($quiz->load('questions'));
        });
    }

    public function grade(Request $r, Answer $answer)
    {
        $this->authorize('manage', $answer);
        $d = $r->validate(['score' => ['required', 'numeric', 'min:0']]);
        abort_if((float) $d['score'] > (float) $answer->question->points, 422, 'العلامة تتجاوز علامة السؤال.');

        return DB::transaction(function () use ($answer, $d) {
            $attempt = QuizAttempt::whereKey($answer->attempt_id)->lockForUpdate()->firstOrFail();
            abort_unless($attempt->submitted_at !== null, 409, 'انتظر تسليم الطالب للاختبار.');
            $answer = Answer::whereKey($answer->id)->lockForUpdate()->firstOrFail();
            $answer->update(['score' => $d['score'], 'status' => 'teacher_reviewed']);
            $answers = $attempt->answers()->get()->keyBy('question_id');
            $pending = $answers->contains(fn ($x) => $x->status === 'pending' || $x->score === null);
            $score = $pending ? null : $attempt->quiz->questions()->get()->sum(fn ($q) => min((float) $q->points, max(0, (float) ($answers->get($q->id)?->score ?? 0))));
            $attempt->update(['score' => $score, 'status' => $pending ? 'submitted' : 'graded']);

            return $this->success(['answer' => $answer, 'attempt' => $attempt]);
        });
    }
}
