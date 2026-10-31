<?php

namespace App\Http\Controllers\Admin;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentsController extends AdminController
{
    private function query()
    {
        return Enrollment::with(['student.user', 'teacher.user', 'enrollable', 'groupEnrollments.group']);
    }

    public function index(Request $request)
    {
        $data = $this->filters($request);
        $request->validate(['type' => ['nullable', 'in:teacher_subject,unit,content,paid_booking'], 'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id']]);
        $query = $this->query();
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(fn ($q) => $q->where('id', ctype_digit($term) ? (int) $term : 0)->orWhereHas('student.user', fn ($q) => $q->where('name', 'like', "%{$term}%"))->orWhereHas('teacher.user', fn ($q) => $q->where('name', 'like', "%{$term}%")));
        }
        if ($request->filled('type')) {
            $query->where('enrollable_type', $request->input('type'));
        }
        if (! empty($data['status'])) {
            if ($data['status'] === 'expired') {
                $query->where(fn ($q) => $q->where('status', 'expired')->orWhere(fn ($q) => $q->where('status', 'active')->where('expires_at', '<=', now())));
            } else {
                $query->where('status', $data['status']);
            }
        }
        if (! empty($data['from'])) {
            $query->whereDate('starts_at', '>=', $data['from']);
        }if (! empty($data['to'])) {
            $query->whereDate('starts_at', '<=', $data['to']);
        }
        if ($request->boolean('export')) {
            return $this->csv($query->orderBy('id'), ['المعرف' => 'id', 'الطالب' => 'student.user.name', 'المدرس' => 'teacher.user.name', 'النوع' => 'enrollable_type', 'الحالة' => 'status', 'البداية' => 'starts_at', 'الانتهاء' => 'expires_at'], 'academic-enrollments');
        }
        $items = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $selected = $request->filled('enrollment_id') ? $this->query()->findOrFail($request->integer('enrollment_id')) : null;
        $payments = $selected ? Payment::where('payable_type', (new Enrollment)->getMorphClass())->where('payable_id', $selected->id)->latest('id')->get() : collect();
        $stats = ['total' => Enrollment::count(), 'active' => Enrollment::where('status', 'active')->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(), 'pending' => Enrollment::where('status', 'pending')->count(), 'paid' => Enrollment::where('enrollable_type', 'paid_booking')->count()];

        return view('admin.enrollments.index', compact('items', 'selected', 'payments', 'stats'));
    }

    public function create()
    {
        $students = Student::with('user')->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->get();
        $courses = TeacherSubject::with(['teacher.user', 'subject', 'units.lessons.contents'])->where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active'))->whereHas('teacher', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')))->get();

        return view('admin.enrollments.form', compact('students', 'courses'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['student_id' => ['required', 'integer', 'exists:students,id'], 'target' => ['required', 'string', 'regex:/^(teacher_subject|unit|content):[1-9][0-9]*$/'], 'starts_at' => ['required', 'date'], 'expires_at' => ['nullable', 'date', 'after:starts_at'], 'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        [$type,$id] = explode(':', $data['target']);
        $class = match ($type) {
            'teacher_subject' => TeacherSubject::class,'unit' => Unit::class,'content' => Content::class
        };
        $item = $class::findOrFail($id);
        $course = match ($type) {
            'teacher_subject' => $item,'unit' => $item->teacherSubject,'content' => $item->lesson?->unit?->teacherSubject
        };
        abort_unless($course, 404);
        DB::transaction(function () use ($data, $class, $id, $type, $course) {
            $teacher = Teacher::whereKey($course->teacher_id)->lockForUpdate()->firstOrFail();
            $student = Student::whereKey($data['student_id'])->lockForUpdate()->firstOrFail();
            $item = $class::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($teacher->status !== 'active' || $teacher->user?->status !== 'active' || $student->status !== 'active' || $student->user?->status !== 'active' || $course->fresh()->status !== 'active' || $course->subject?->status !== 'active' || ($type !== 'teacher_subject' && $item->status !== 'published') || ($type === 'content' && ($item->lesson?->status !== 'published' || $item->lesson?->unit?->status !== 'published'))) {
                throw ValidationException::withMessages(['target' => 'يتطلب المنح طالباً ومدرساً ومادة فعالين، ومحتوى منشوراً ضمن منهج منشور.']);
            }
            $start = Carbon::parse($data['starts_at']);
            $end = empty($data['expires_at']) ? null : Carbon::parse($data['expires_at']);
            $existing = Enrollment::where('student_id', $student->id)->where('enrollable_type', $type)->where('enrollable_id', $id)->whereIn('status', ['active', 'pending'])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $start));
            if ($end) {
                $existing->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', $end));
            }
            if ($existing->exists()) {
                throw ValidationException::withMessages(['target' => 'يوجد تسجيل فعال أو معلق لنفس العنصر ضمن هذه الفترة.']);
            }
            $record = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollable_type' => $type, 'enrollable_id' => $id, 'price' => 0, 'status' => 'active', 'starts_at' => $start, 'expires_at' => $end]);
            $this->audit('enrollment.granted', $record, ['reason' => $data['reason'], 'source' => 'administrative_grant']);
        }, 3);

        return redirect()->route('admin.enrollments.index')->with('success', 'تم منح التسجيل إدارياً دون تسجيل دفعة مالية.');
    }

    public function cancel(Request $request, Enrollment $enrollment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        DB::transaction(function () use ($enrollment, $data) {
            Teacher::whereKey($enrollment->teacher_id)->lockForUpdate()->firstOrFail();
            Student::whereKey($enrollment->student_id)->lockForUpdate()->firstOrFail();
            $record = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            if ($record->enrollable_type === 'paid_booking' || (float) $record->price !== 0.0 || Payment::where('payable_type', (new Enrollment)->getMorphClass())->where('payable_id', $record->id)->exists() || $record->status !== 'active') {
                throw ValidationException::withMessages(['reason' => 'الإلغاء المباشر للتسجيل المجاني فقط. التسجيل المدفوع يعالج عبر طلب استرداد.']);
            }
            if ($record->sessions()->where('status', 'ongoing')->exists() || $record->sessions()->where('status', 'scheduled')->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['reason' => 'لا يمكن إنهاء تسجيل له جلسة جارية.']);
            }
            $record->update(['status' => 'cancelled']);
            $record->groupEnrollments()->where('status', 'active')->update(['status' => 'removed', 'left_at' => now()]);
            $record->sessions()->where('status','scheduled')->where('starts_at','>',now())->update(['status' => 'cancelled']);
            $this->audit('enrollment.cancelled',$record,['reason' => $data['reason']]);
        }, 3);

        return back()->with('success','تم إنهاء التسجيل المجاني مع حفظ السجل.');
    }
}
