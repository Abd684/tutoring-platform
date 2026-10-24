<?php

namespace App\Http\Controllers\Admin;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Services\VideoMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContentController extends AdminController
{
    public function index(Request $request)
    {
        $data = $this->filters($request);
        $query = Content::with('lesson.unit.teacherSubject.teacher.user');
        if (! empty($data['search'])) {
            $query->where('title', 'like', '%'.$data['search'].'%');
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if ($request->filled('lesson_id')) {
            $request->validate(['lesson_id' => ['integer', 'exists:lessons,id']]);
            $query->where('lesson_id', $request->integer('lesson_id'));
        }
        $items = $query->orderByDesc('id')->paginate(12)->withQueryString();
        $tree = TeacherSubject::with(['teacher.user', 'subject', 'units.lessons'])->orderBy('id')->get();

        return view('admin.content.index', compact('items', 'tree'));
    }

    public function create(Request $request)
    {
        $content = new Content(['status' => 'draft', 'type' => 'video', 'order_no' => 1, 'price' => 0, 'lesson_id' => $request->integer('lesson_id') ?: null]);

        return $this->form($content);
    }

    public function edit(Content $content)
    {
        return $this->form($content);
    }

    private function form(Content $content)
    {
        $lessons = Lesson::with('unit.teacherSubject.teacher.user')->orderBy('id')->get();
        $content->load('assets', 'quizzes');

        return view('admin.content.form', compact('content', 'lessons'));
    }

    public function store(Request $request)
    {
        return $this->save($request, new Content);
    }

    public function update(Request $request, Content $content)
    {
        return $this->save($request, $content);
    }

    private function save(Request $request, Content $content)
    {
        $data = $request->validate(['lesson_id' => ['required', 'integer', 'exists:lessons,id'], 'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:30000'], 'type' => ['required', 'in:video,pdf,quiz,link'], 'price' => ['nullable', 'numeric', 'between:0,9999999999.99'],
            'order_no' => ['required', 'integer', 'min:0'], 'status' => ['required', 'in:draft,published,archived,scheduled'],
            'scheduled_publish_at' => ['required_if:status,scheduled', 'nullable', 'date', 'after:now'],
            'learning_objectives' => ['nullable', 'string', 'max:10000'], 'file' => ['nullable', 'file', 'max:512000']]);
        if ($content->exists && $content->type !== $data['type'] && ($content->assets()->exists() || $content->quizzes()->exists())) {
            throw ValidationException::withMessages(['type' => ['لا يمكن تغيير نوع محتوى مرتبط بملفات أو اختبارات. أنشئ محتوى جديداً.']]);
        }
        $data['learning_objectives'] = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $data['learning_objectives'] ?? ''))));
        $scheduled = $data['status'] === 'scheduled';
        $data['scheduled_publish_at'] = $scheduled ? $data['scheduled_publish_at'] : null;
        if ($scheduled) {
            $data['status'] = 'draft';
        } elseif ($data['status'] === 'published' && ! $content->published_at) {
            $data['published_at'] = now();
        }
        unset($data['file']);
        $path = null;
        $disk = config('filesystems.media_disk', 'local');
        try {
            DB::transaction(function () use ($request, $data, $content, $disk, &$path) {
                if ($content->exists) {
                    Content::whereKey($content->id)->lockForUpdate()->firstOrFail();
                }
                $content->fill($data)->save();
                if ($request->hasFile('file')) {
                    $file = $request->file('file');
                    $allowed = $content->type === 'pdf' ? ['application/pdf'] : ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'];
                    if (! in_array($content->type, ['pdf', 'video'], true) || ! in_array($file->getMimeType(), $allowed, true) || $disk === 'public') {
                        throw ValidationException::withMessages(['file' => ['ارفع ملفاً مطابقاً لنوع المحتوى على تخزين خاص.']]);
                    }
                    $teacherId = $content->lesson->unit->teacherSubject->teacher_id;
                    $path = $file->store("teachers/{$teacherId}/contents/{$content->id}", $disk);
                    abort_unless($path, 500);
                    $asset = ContentAsset::create(['content_id' => $content->id, 'disk' => $disk, 'storage_key' => $path, 'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()), 'encryption_status' => 'pending', 'status' => 'active']);
                    app(VideoMetadata::class)->probe($asset, $file->getRealPath());
                }
                $this->audit('content.saved', $content, ['scheduled' => $data['scheduled_publish_at'] !== null]);
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk($disk)->delete($path);
            }throw $error;
        }

        return redirect()->route('admin.content.edit', $content)->with('success', 'تم حفظ المحتوى.');
    }

    public function archive(Content $content)
    {
        $content->update(['status' => 'archived', 'scheduled_publish_at' => null]);
        $this->audit('content.archived', $content);

        return back()->with('success', 'تمت أرشفة المحتوى مع حفظ تاريخه.');
    }

    public function asset(ContentAsset $asset)
    {
        $disk = Storage::disk($asset->disk);
        abort_if($asset->disk === 'public' || ! $disk->exists($asset->storage_key), 404);
        $headers = ['Content-Type' => $asset->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (config('filesystems.disks.'.$asset->disk.'.driver') === 'local') {
            return response()->file($disk->path($asset->storage_key), $headers);
        }

        return $disk->response($asset->storage_key, null, $headers, 'inline');
    }

    public function hierarchy(Request $request)
    {
        $data = $request->validate(['kind' => ['required', 'in:subject,unit,lesson'], 'title' => ['required', 'string', 'max:255'], 'parent_id' => ['nullable', 'integer', 'min:1']]);
        DB::transaction(function () use ($data) {
            $record = match ($data['kind']) {
                'subject' => Subject::create(['name' => $data['title'], 'status' => 'active']),
                'unit' => Unit::create(['teacher_subject_id' => TeacherSubject::findOrFail($data['parent_id'] ?? 0)->id, 'title' => $data['title'], 'order_no' => 0, 'status' => 'draft']),
                'lesson' => Lesson::create(['unit_id' => Unit::findOrFail($data['parent_id'] ?? 0)->id, 'title' => $data['title'], 'order_no' => 0, 'status' => 'draft']),
            };
            $this->audit('curriculum.created', $record);
        });

        return back()->with('success', 'تمت إضافة العنصر. الوحدات والدروس الجديدة تبدأ كمسودة.');
    }

    public function quiz(Content $content)
    {
        abort_unless($content->type === 'quiz', 404);
        $quiz = $content->quizzes()->orderBy('id')->first() ?? new Quiz(['content_id' => $content->id, 'status' => 'draft', 'attempts_allowed' => 1, 'duration_minutes' => 30]);
        $questions = $quiz->exists ? $quiz->questions()->orderBy('order_no')->get() : collect();
        $locked = $quiz->exists && $quiz->attempts()->exists();

        return view('admin.content.quiz', compact('content', 'quiz', 'questions', 'locked'));
    }

    public function updateHierarchy(Request $request, string $kind, int $item)
    {
        abort_unless(in_array($kind, ['unit', 'lesson'], true), 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'status' => ['required', 'in:draft,published,archived'], 'price' => ['nullable', 'numeric', 'between:0,9999999999.99']]);
        $record = $kind === 'unit' ? Unit::findOrFail($item) : Lesson::findOrFail($item);
        if ($kind === 'lesson') {
            unset($data['price']);
        }
        DB::transaction(function () use ($record, $data) {
            $record->update($data);
            $this->audit('curriculum.updated', $record);
        });

        return back()->with('success', 'تم تحديث عنصر المنهج.');
    }

    public function saveQuiz(Request $request, Content $content)
    {
        abort_unless($content->type === 'quiz', 404);
        $data = $request->validate(['duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'attempts_allowed' => ['required', 'integer', 'between:1,1000'], 'status' => ['required', 'in:draft,published,archived'],
            'questions' => ['sometimes', 'array', 'max:100'], 'questions.*.id' => ['nullable', 'integer'], 'questions.*.body' => ['required', 'string', 'max:10000'],
            'questions.*.type' => ['required', 'in:mcq,true_false,essay'], 'questions.*.points' => ['required', 'numeric', 'between:0,999999'],
            'questions.*.options' => ['nullable', 'string', 'max:10000'], 'questions.*.correct_answer' => ['nullable', 'string', 'max:4000'], 'questions.*.remove' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($content, $data) {
            Content::whereKey($content->id)->lockForUpdate()->firstOrFail();
            $quiz = $content->quizzes()->orderBy('id')->lockForUpdate()->first() ?? new Quiz(['content_id' => $content->id]);
            if ($quiz->exists && $quiz->attempts()->exists() && isset($data['questions'])) {
                throw ValidationException::withMessages(['questions' => ['أسئلة اختبار له محاولات محفوظة لا يمكن تغييرها.']]);
            }
            $quiz->fill(array_intersect_key($data, array_flip(['duration_minutes', 'attempts_allowed', 'status'])))->save();
            foreach ($data['questions'] ?? [] as $index => $row) {
                $question = empty($row['id']) ? new Question(['quiz_id' => $quiz->id]) : $quiz->questions()->findOrFail($row['id']);
                if (! empty($row['remove'])) {
                    $question->delete();

                    continue;
                }
                $options = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $row['options'] ?? '')), fn ($s) => $s !== ''));
                if ($row['type'] === 'mcq' && (count($options) < 2 || count($options) > 10 || ! ctype_digit((string) ($row['correct_answer'] ?? '')) || ! array_key_exists((int) $row['correct_answer'], $options))) {
                    throw ValidationException::withMessages(['questions' => ['سؤال الاختيار يحتاج خيارين إلى عشرة وخانة إجابة مرجعية برقم يبدأ من صفر.']]);
                }
                if ($row['type'] === 'true_false') {
                    $options = ['صحيح', 'خطأ'];
                    if (! in_array($row['correct_answer'] ?? null, ['0', '1'], true)) {
                        throw ValidationException::withMessages(['questions' => ['الإجابة المرجعية للصحيح والخطأ تكون0 أو1.']]);
                    }
                }
                $rubric = ['correct_answer' => $row['correct_answer'] ?? null];
                if ($row['type'] !== 'essay') {
                    $rubric['options'] = $options;
                }
                $question->fill(['body' => $row['body'], 'type' => $row['type'], 'points' => $row['points'], 'order_no' => $index, 'rubric_json' => $rubric])->save();
            }
            if ($quiz->status === 'published' && ! $quiz->questions()->exists()) {
                throw ValidationException::withMessages(['status' => ['لا يمكن نشر اختبار دون أسئلة.']]);
            }
            $this->audit('quiz.saved', $quiz);
        });

        return back()->with('success', 'تم حفظ الاختبار. الإجابات المرجعية متاحة للإدارة ولا تُرسل للطالب.');
    }
}
