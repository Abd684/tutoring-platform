<?php

namespace App\Http\Controllers\Admin;

use App\Models\Group;
use App\Models\Individual;
use App\Models\Session;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Services\PaidLessonBookings;
use App\Services\SessionRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionsController extends AdminController
{
    public function index(Request $request, User $teacherUser)
    {
        $data = $this->filters($request);
        $scope = Session::where('teacher_id', $teacherUser->teacher->id);
        $query = (clone $scope)->with(['teacher.user', 'target']);
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(fn ($q) => $q->whereHas('teacher.user', fn ($q) => $q->where('name', 'like', "%{$term}%"))->orWhereHasMorph('target', [Group::class], fn ($q) => $q->where('name', 'like', "%{$term}%")));
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

        $stats = ['total' => (clone $scope)->count(), 'scheduled' => (clone $scope)->where('status', 'scheduled')->count(), 'completed' => (clone $scope)->where('status', 'completed')->count(), 'cancelled' => (clone $scope)->where('status', 'cancelled')->count()];

        return view('admin.sessions.index', compact('items', 'stats'));
    }

    public function create(User $teacherUser)
    {
        $teachers = collect([$teacherUser->teacher]);
        $groups = Group::whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $teacherUser->teacher->id))->with(['teacherSubject.teacher.user', 'teacherSubject.subject'])->whereIn('status', ['active', 'forming'])->get();
        $individuals = Individual::whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $teacherUser->teacher->id))->with(['student.user', 'teacherSubject.teacher.user'])->where('status', 'active')->get();
        $availability = TeacherAvailability::where('teacher_id', $teacherUser->teacher->id)->where('status', 'active')->get();

        return view('admin.sessions.form', compact('teachers', 'groups', 'individuals', 'availability'));
    }

    public function store(Request $request, User $teacherUser)
    {
        $data = $request->validate(['teacher_id' => ['required', 'integer', 'exists:teachers,id'], 'type' => ['required', 'in:individual,group'],
            'type_id' => ['required', 'integer', 'min:1'], 'group_id' => ['prohibited'], 'enrollment_id' => ['prohibited'], 'availability_id' => ['nullable', 'integer', 'exists:teacher_availabilities,id'],
            'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'capacity' => ['nullable', 'integer', 'min:1']]);
        abort_unless((int) $data['teacher_id'] === $teacherUser->teacher->id, 404);
        $session = DB::transaction(function () use ($data) {
            $teacher = Teacher::whereKey($data['teacher_id'])->lockForUpdate()->firstOrFail();
            if ($teacher->status !== 'active' || $teacher->user?->status !== 'active') {
                throw ValidationException::withMessages(['teacher_id' => ['المدرس غير فعال.']]);
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

        return redirect()->route('admin.sessions.show', ['teacherUser' => $teacherUser, 'session' => $session])->with('success', 'تمت جدولة الجلسة.');
    }

    public function show(User $teacherUser, Session $session)
    {
        $session->load(['teacher.user', 'target.teacherSubject.subject', 'attendance.student.user']);

        return view('admin.sessions.show', compact('session'));
    }

    public function cancel(User $teacherUser, Session $session)
    {
        if ($session->paidBooking()->exists()) {
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
