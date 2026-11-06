<?php

namespace App\Http\Controllers\Admin;

use App\Models\Enrollment;
use App\Models\DeviceTransferRequest;
use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Payment;
use App\Models\RefreshToken;
use App\Models\Region;
use App\Models\School;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\GroupMembershipRules;
use App\Services\DeviceTransfers;
use App\Services\OnlineRefunds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountsController extends AdminController
{
    private function teacher(Request $request): bool
    {
        return $request->route('kind') === 'teacher';
    }

    private function base(Request $request): string
    {
        return $this->teacher($request) ? 'teachers' : 'students';
    }

    public function index(Request $request)
    {
        $isTeacher = $this->teacher($request);
        $data = $this->filters($request);
        $model = $isTeacher ? Teacher::class : Student::class;
        $query = $model::with('user.region')->whereHas('user');
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->whereHas('user', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")));
        }
        if (! empty($data['status'])) {
            $query->whereHas('user', fn ($q) => $q->where('status', $data['status']));
        }
        if ($request->boolean('export')) {
            return $this->csv($query->orderBy('id'), ['معرف الملف' => 'id', 'معرف المستخدم' => 'user_id', 'الاسم' => 'user.name', 'البريد' => 'user.email', 'الهاتف' => 'user.phone', 'الحالة' => 'user.status'], $isTeacher ? 'teachers' : 'students');
        }
        $items = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $stats = ['total' => $model::count(), 'active' => $model::where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->count(),
            'suspended' => $model::whereHas('user', fn ($q) => $q->where('status', 'suspended'))->count()];

        return view('admin.accounts.index', compact('items', 'isTeacher', 'stats'));
    }

    public function create(Request $request)
    {
        $isTeacher = $this->teacher($request);
        $user = new User(['status' => 'active']);
        $account = $isTeacher ? new Teacher(['status' => 'pending_review']) : new Student(['status' => 'active']);

        return $this->form($isTeacher, $user, $account);
    }

    public function edit(Request $request, User $user)
    {
        $isTeacher = $this->teacher($request);
        $request->validate(['tab' => ['nullable', 'in:profile,courses,payments,subscriptions']]);
        abort_unless($user->role === ($isTeacher ? 'teacher' : 'student'), 404);

        return $this->form($isTeacher, $user, $isTeacher ? $user->teacher : $user->student);
    }

    private function form(bool $isTeacher, User $user, $account)
    {
        abort_unless($account, 404);
        $subjects = Subject::where('status', 'active')->orderBy('name')->get();
        $regions = Region::orderBy('name')->get();
        $schools = School::orderBy('name')->get();
        $courses = TeacherSubject::with(['teacher.user', 'subject'])->where('status', 'active')->get();
        $groups = Group::with('teacherSubject.subject')->whereIn('status', ['active', 'forming'])->get();

        return view('admin.accounts.form', compact('isTeacher', 'user', 'account', 'subjects', 'regions', 'schools', 'courses', 'groups'));
    }

    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function update(Request $request, User $user)
    {
        return $this->save($request, $user);
    }

    private function save(Request $request, ?User $user)
    {
        $isTeacher = $this->teacher($request);
        $role = $isTeacher ? 'teacher' : 'student';
        if ($user) {
            abort_unless($user->role === $role, 404);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => $user ? ['sometimes', 'nullable', Rule::in([$user->email])] : ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => $user ? ['sometimes', 'nullable', Rule::in([$user->phone])] : ['required', 'string', 'max:20', Rule::unique('users', 'phone')],
            'password' => [$user ? 'prohibited' : 'required', 'string', 'min:8', 'max:255', 'confirmed'],
            'status' => ['required', 'in:active,suspended'], 'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'specialization' => ['nullable', 'string', 'max:255'], 'bio' => ['nullable', 'string', 'max:10000'],
            'experience_years' => ['nullable', 'integer', 'between:0,100'], 'teacher_status' => ['sometimes', 'in:active,pending_review,suspended'],
            'grade' => ['nullable', 'string', 'max:50'], 'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'subject_ids' => ['sometimes', 'array'], 'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')->where('status', 'active')],
            'subject_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'grant_access' => ['sometimes', 'boolean'], 'course_id' => ['nullable', 'integer', 'exists:teacher_subjects,id'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'], 'grant_reason' => ['required_if:grant_access,1', 'nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        if (! $isTeacher && ! empty($data['school_id']) && (int) School::find($data['school_id'])->region_id !== (int) ($data['region_id'] ?? 0)) {
            throw ValidationException::withMessages(['school_id' => ['المدرسة لا تتبع المنطقة المختارة.']]);
        }
        if ($user) {
            unset($data['email'], $data['phone']);
        }
        $saved = DB::transaction(function () use ($request, $data, $user, $isTeacher, $role) {
            $identity = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'region_id']));
            if ($user) {
                $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $target->update($identity);
            } else {
                $target = User::create([...$identity, 'password_hash' => Hash::make($data['password']), 'role' => $role, 'status' => $data['status']]);
            }
            if ($isTeacher) {
                $previousTeacherStatus = $target->teacher?->status;
                $target->teacher()->updateOrCreate(['user_id' => $target->id], array_intersect_key($data, array_flip(['specialization', 'bio', 'experience_years'])) + ['status' => $data['teacher_status'] ?? 'pending_review']);
                $target->unsetRelation('teacher');
                if (($data['teacher_status'] ?? null) === 'suspended' && $previousTeacherStatus !== 'suspended') {
                    app(OnlineRefunds::class)->teacherSuspended($target->teacher()->firstOrFail(), $request->user()->id);
                    $target->tokens()->delete();
                    RefreshToken::where('user_id', $target->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                }
                if (! $user) {
                    foreach (array_unique($data['subject_ids'] ?? []) as $id) {
                        $target->teacher->teacherSubjects()->create(['subject_id' => $id, 'price' => $data['subject_price'] ?? 0, 'status' => 'active']);
                    }
                }
            } else {
                $target->student()->updateOrCreate(['user_id' => $target->id], ['grade' => $data['grade'] ?? null, 'school_id' => $data['school_id'] ?? null, 'status' => 'active']);
            }
            if ($user && $target->status !== $data['status']) {
                $admin = app(\App\Http\Controllers\Api\V1\AdminController::class);
                $data['status'] === 'active' ? $admin->activateUser($request, $target) : $admin->suspendUser($request, $target);
                if ($data['status'] !== 'active') {
                    $target->tokens()->delete();
                    RefreshToken::where('user_id', $target->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                }
            }
            if (! $isTeacher && ! $user && ! empty($data['grant_access'])) {
                $course = TeacherSubject::with(['teacher.user', 'subject'])->lockForUpdate()->find($data['course_id'] ?? 0);
                if (! $course || $course->status !== 'active' || $course->subject?->status !== 'active' || $course->teacher?->status !== 'active' || $course->teacher->user?->status !== 'active' || $target->status !== 'active') {
                    throw ValidationException::withMessages(['course_id' => ['اختر مادة فعالة لطالب فعال لمنح الوصول.']]);
                }
                $enrollment = Enrollment::create(['student_id' => $target->student->id, 'teacher_id' => $course->teacher_id, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $course->id,
                    'status' => 'active', 'price' => 0, 'starts_at' => now(), 'expires_at' => $data['expires_at'] ?? null]);
                if (! empty($data['group_id'])) {
                    $group = Group::whereKey($data['group_id'])->lockForUpdate()->firstOrFail();
                    app(GroupMembershipRules::class)->validate($group, $target->student);
                    if (! in_array($group->status, ['active', 'forming'], true) || $group->groupStudents()->where('status', 'active')->count() >= $group->capacity) {
                        throw ValidationException::withMessages(['group_id' => ['المجموعة مغلقة أو ممتلئة.']]);
                    }
                    GroupStudent::create(['group_id' => $group->id, 'student_id' => $target->student->id, 'status' => 'active', 'joined_at' => now()]);
                }
                $this->audit('access.granted', $enrollment, ['reason' => $data['grant_reason']]);
            }
            $this->audit($user ? 'account.updated' : 'account.created', $target, ['role' => $role]);

            return $target;
        });

        return redirect()->route('admin.'.$this->base($request).'.show', $saved)->with('success', 'تم حفظ بيانات الحساب بنجاح.');
    }

    public function show(Request $request, User $user)
    {
        $isTeacher = $this->teacher($request);
        $request->validate(['tab' => ['nullable', 'in:profile,courses,payments,subscriptions']]);
        abort_unless($user->role === ($isTeacher ? 'teacher' : 'student'), 404);
        $account = $isTeacher ? $user->teacher : $user->student;
        abort_unless($account, 404);
        $user->load('region');
        $enrollments = $isTeacher ? Enrollment::where('teacher_id', $account->id) : Enrollment::where('student_id', $account->id);
        $payments = Payment::with('payer')->where('payable_type', (new Enrollment)->getMorphClass())->whereIn('payable_id', (clone $enrollments)->select('id'))->latest('id')->limit(20)->get();
        $courses = $isTeacher ? $account->teacherSubjects()->with('subject')->get() : $account->enrollments()->with(['teacher.user', 'enrollable'])->latest('id')->limit(30)->get();
        $sessions = Session::with(['teacher.user', 'target'])->when($isTeacher, fn ($q) => $q->where('teacher_id', $account->id), fn ($q) => $q->forStudent($account->id))->latest('starts_at')->limit(20)->get();
        $subscriptions = $isTeacher ? $account->subscriptions()->with('plan')->latest('starts_at')->get() : collect();
        $availability = $isTeacher ? $account->availabilities()->orderBy('date')->limit(20)->get() : collect();

        $tab = $request->input('tab', 'profile');
        $deviceTransfers = $isTeacher ? collect() : DeviceTransferRequest::with(['sourceStudentDevice.device', 'targetDevice'])
            ->where('student_id', $account->id)->latest('id')->limit(20)->get();

        return view('admin.accounts.show', compact('user', 'account', 'isTeacher', 'payments', 'courses', 'sessions', 'subscriptions', 'availability', 'tab', 'deviceTransfers'));
    }

    public function approveDeviceTransfer(Request $request, User $user, DeviceTransferRequest $transfer)
    {
        abort_unless($user->role === 'student' && $user->student
            && (int) $transfer->student_id === (int) $user->student->id, 404);

        $resolved = DB::transaction(function () use ($transfer) {
            $resolved = app(DeviceTransfers::class)->resolve($transfer->id, 'approve');
            if (! in_array($resolved->status, ['approved', 'auto_approved'], true)) {
                throw ValidationException::withMessages(['operation' => ['تعذر الموافقة على طلب النقل؛ راجع حالة الطلب والحساب والجهاز.']]);
            }
            $this->audit('device_transfer.approved', $resolved);

            return $resolved;
        });

        return redirect()->route('admin.students.show', $user)->with('success', 'تمت الموافقة على نقل الجهاز وإبطال جلسات الجهاز القديم.');
    }

    public function status(Request $request, User $user)
    {
        abort_unless($user->role === ($this->teacher($request) ? 'teacher' : 'student'), 404);
        $data = $request->validate(['status' => ['required', 'in:active,suspended'], 'reason' => ['nullable', 'string', 'max:500']]);
        $controller = app(\App\Http\Controllers\Api\V1\AdminController::class);
        $response = DB::transaction(function () use ($data, $controller, $request, $user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $response = $data['status'] === 'active' ? $controller->activateUser($request, $user) : $controller->suspendUser($request, $user);
            if ($response->getStatusCode() < 400) {
                if ($data['status'] !== 'active') {
                    $user->tokens()->delete();
                    RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                }
                $this->audit('account.status_updated', $user, ['status' => $data['status']]);
            }

            return $response;
        });

        return $this->apiResult($response, 'تم تحديث حالة الحساب.');
    }
}
