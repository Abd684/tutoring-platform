<?php

namespace App\Http\Controllers\Admin;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Services\PaidLessonBookings;
use App\Services\SessionRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionsController extends AdminController
{
    public function index(Request $request)
    {
        $data = $this->filters($request);
        $query = Session::with(['teacher.user', 'group', 'enrollment.student.user']);
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(fn ($q) => $q->whereHas('teacher.user', fn ($q) => $q->where('name', 'like', "%{$term}%"))->orWhereHas('group', fn ($q) => $q->where('name', 'like', "%{$term}%")));
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['teacher_id'])) {
            $query->where('teacher_id', $data['teacher_id']);
        }
        if (! empty($data['from'])) {
            $query->whereDate('starts_at', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('starts_at', '<=', $data['to']);
        }
        if ($request->boolean('export')) {
            return $this->csv($query->orderBy('id'), ['المعرف' => 'id', 'المدرس' => 'teacher.user.name', 'النوع' => 'type', 'البداية' => 'starts_at', 'النهاية' => 'ends_at', 'الحالة' => 'status'], 'sessions');
        }
        $items = $query->orderByDesc('starts_at')->paginate(15)->withQueryString();
        $teachers = Teacher::with('user')->get();
        $stats = ['total' => Session::count(), 'scheduled' => Session::where('status', 'scheduled')->count(), 'completed' => Session::where('status', 'completed')->count(), 'cancelled' => Session::where('status', 'cancelled')->count()];

        return view('admin.sessions.index', compact('items', 'teachers', 'stats'));
    }

    public function create()
    {
        $teachers = Teacher::with('user')->where('status', 'active')->get();
        $groups = Group::with(['teacherSubject.teacher.user', 'teacherSubject.subject'])->whereIn('status', ['active', 'forming'])->get();
        $enrollments = Enrollment::with(['student.user', 'teacher.user'])->where('status', 'active')->where('enrollable_type', '!=', 'paid_booking')->get();
        $availability = TeacherAvailability::where('status', 'active')->get();

        return view('admin.sessions.form', compact('teachers', 'groups', 'enrollments', 'availability'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['teacher_id' => ['required', 'integer', 'exists:teachers,id'], 'type' => ['required', 'in:INDIVIDUAL,GROUP'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'], 'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'], 'availability_id' => ['nullable', 'integer', 'exists:teacher_availabilities,id'],
            'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'capacity' => ['nullable', 'integer', 'min:1']]);
        $session = DB::transaction(function () use ($data) {
            $teacher = Teacher::whereKey($data['teacher_id'])->lockForUpdate()->firstOrFail();
            if ($teacher->status !== 'active' || $teacher->user?->status !== 'active') {
                throw ValidationException::withMessages(['teacher_id' => ['المدرس غير فعال.']]);
            }
            if (! empty($data['group_id']) && Group::findOrFail($data['group_id'])->teacherSubject->teacher_id !== $teacher->id) {
                throw ValidationException::withMessages(['group_id' => ['المجموعة لا تخص المدرس المختار.']]);
            }
            if (! empty($data['enrollment_id'])) {
                Student::whereKey(Enrollment::findOrFail($data['enrollment_id'])->student_id)->lockForUpdate()->firstOrFail();
            }
            $rules = app(SessionRules::class);
            $state = $rules->validate([...$data, 'status' => 'scheduled'], true);
            if ($rules->conflicts($state)) {
                throw ValidationException::withMessages(['starts_at' => ['يوجد تعارض لدى المدرس أو أحد الطلاب.']]);
            }
            $session = Session::create($state);
            $this->audit('session.created', $session);

            return $session;
        }, 3);

        return redirect()->route('admin.sessions.show', $session)->with('success', 'تمت جدولة الجلسة.');
    }

    public function show(Session $session)
    {
        $session->load(['teacher.user', 'group.teacherSubject.subject', 'enrollment.student.user', 'attendance.enrollment.student.user']);

        return view('admin.sessions.show', compact('session'));
    }

    public function cancel(Session $session)
    {
        if ($session->enrollment?->enrollable_type === 'paid_booking') {
            app(PaidLessonBookings::class)->cancelSession($session, 'admin', auth()->id());
        } else {
            DB::transaction(function () use ($session) {
                Teacher::whereKey($session->teacher_id)->lockForUpdate()->firstOrFail();
                $session = Session::whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($session->status === 'cancelled') {
                    return;
                }
                abort_unless($session->status === 'scheduled' && $session->starts_at->isFuture(), 409, 'لا يمكن إلغاء جلسة بدأت أو انتهت.');
                $session->update(['status' => 'cancelled']);
                $this->audit('session.cancelled', $session);
            });
        }

        return back()->with('success', 'تم إلغاء الجلسة مع حفظ سجلها.');
    }
}
