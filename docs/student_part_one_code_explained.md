# كود الجزء الأول مع الشرح — النسخة الحالية

تم حذف ربط الجلسة بالدرس وجدول الغرف والمايغريشينين بناءً على الطلب. المواعيد حسب المدرس فقط: GET /api/v1/teachers/{teacher}/available-sessions. مسارا حفظ الغرفة ودخول الحصة حُذفا؛ دخول الحصة المباشرة مؤجل. لا توجد تغييرات جديدة ببنية القاعدة لهذا الجزء. الملفات المشتركة تتضمن كوداً سابقاً؛ التعديلات موضحة أدناه. تفاصيل requests/responses في [student_part_one_api.md](student_part_one_api.md).

## app/Models/Session.php

نموذج الجلسة، عاد بدون lesson_id وعلاقة الدرس؛ بقية العلاقات السابقة بقيت.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'teacher_id',
        'availability_id',
        'type',
        'group_id',
        'enrollment_id',
        'starts_at',
        'ends_at',
        'capacity',
        'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'capacity' => 'integer'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }


    public function availability(): BelongsTo
    {
        return $this->belongsTo(TeacherAvailability::class, 'availability_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
```

## app/Services/SessionEligibility.php

enrollments يتحقق من اشتراك فعال بدأ ولم ينتهِ قبل نهاية الجلسة، للطالب عند المدرس نفسه. لا يقيد الاشتراك بدرس أو مادة محددة. conflicts يفحص التداخل عبر جميع اشتراكات الطالب والحجوزات الفردية والمجموعات الفعالة.

```php
<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Session;
use Illuminate\Database\Eloquent\Builder;

class SessionEligibility
{
    public function enrollments(int $studentId, Session $session): Builder
    {
        $query = Enrollment::query()->where('student_id', $studentId)
            ->where('teacher_id', $session->teacher_id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $session->ends_at));

        return $query;
    }

    public function conflicts(int $studentId, Session $session): Builder
    {
        return Session::query()->where('id', '!=', $session->id)
            ->whereIn('status', ['scheduled', 'ongoing'])
            ->where('starts_at', '<', $session->ends_at)->where('ends_at', '>', $session->starts_at)
            ->where(function ($q) use ($studentId) {
                $q->whereHas('enrollment', fn ($q) => $q->where('student_id', $studentId))
                    ->orWhereHas('group.groupEnrollments', fn ($q) => $q->where('status', 'active')
                        ->whereHas('enrollment', fn ($q) => $q->where('student_id', $studentId)));
            });
    }
}
```

## app/Http/Controllers/Api/V1/StudentBookingController.php

جلسات scheduled القادمة الخاصة بصاحب التوكن، فردية وجماعية، مع معرف المدرس والاشتراك وبيانات المادة أو الوحدة أو المحتوى. تحميل مادة المحتوى عبر علاقاته الأصلية لا يعني ربط جلسة بدرس. الملغاة والماضية مستبعدة.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Session;
use App\Models\Student;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentBookingController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $student = Student::query()
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $student) {
            return response()->json([
                'message' => 'Student account not found.',
            ], 404);
        }
        abort_unless($student->status === 'active' && $request->user()->status === 'active', 403);

        /*
         * تحميل العنصر المشترك فيه حسب نوع الـ enrollment.
         *
         * TeacherSubject -> Subject
         * Unit           -> TeacherSubject -> Subject
         * Content        -> Lesson -> Unit -> TeacherSubject -> Subject
         */
        $loadEnrollable = function (MorphTo $morphTo): void {
            $morphTo->morphWith([
                TeacherSubject::class => [
                    'subject',
                ],

                Unit::class => [
                    'teacherSubject.subject',
                ],

                Content::class => [
                    'lesson.unit.teacherSubject.subject',
                ],
            ]);
        };

        $sessions = Session::query()
            ->select([
                'id',
                'teacher_id',
                'availability_id',
                'type',
                'group_id',
                'enrollment_id',
                'starts_at',
                'ends_at',
                'capacity',
                'status',
            ])
            ->with([
                'teacher.user:id,name',

                // Individual session
                'enrollment.enrollable' => $loadEnrollable,

                // Group session
                'group.groupEnrollments' => function ($query) use ($student) {
                    $query
                        ->where('status', 'active')
                        ->whereHas('enrollment', function ($enrollmentQuery) use ($student) {
                            $enrollmentQuery->where('student_id', $student->id);
                        });
                },

                'group.groupEnrollments.enrollment.enrollable' => $loadEnrollable,
            ])

            // الجلسات القادمة فقط
            ->where('starts_at', '>=', now())

            // لا نعيد الجلسات الملغاة أو المنتهية
            ->whereIn('status', [
                'scheduled',
            ])

            // جلسات هذا الطالب فقط
            ->where(function ($query) use ($student) {

                // Individual
                $query->where(function ($q) use ($student) {
                    $q->where('type', 'INDIVIDUAL')->whereHas('enrollment', function ($enrollmentQuery) use ($student) {
                    $enrollmentQuery->where('student_id', $student->id);
                    });
                })

                // Group
                    ->orWhere(function ($q) use ($student) {
                        $q->where('type', 'GROUP')->whereHas(
                        'group.groupEnrollments',
                        function ($groupEnrollmentQuery) use ($student) {
                            $groupEnrollmentQuery
                                ->where('status', 'active')
                                ->whereHas(
                                    'enrollment',
                                    function ($enrollmentQuery) use ($student) {
                                        $enrollmentQuery->where(
                                            'student_id',
                                            $student->id
                                        );
                                    }
                                );
                        }
                        );
                    });
            })

            ->orderBy('starts_at')
            ->get();

        $data = $sessions->map(function (Session $session) {

            /*
             * Individual:
             * sessions.enrollment_id
             *
             * Group:
             * sessions.group_id
             * -> group_enrollments
             * -> enrollment_id
             */

            $linkedEnrollment = $session->type === 'INDIVIDUAL' ? $session->enrollment : null;

            if (! $linkedEnrollment && $session->group) {
                $linkedEnrollment = $session
                    ->group
                    ->groupEnrollments
                    ->first()
                    ?->enrollment;
            }

            return [
                'id' => $session->id,

                'type' => strtolower($session->type),

                'starts_at' => $session->starts_at,

                'ends_at' => $session->ends_at,

                'status' => $session->status,

                'teacher' => [
                    'id' => $session->teacher_id,
                    'name' => $session->teacher?->user?->name,
                ],

                'enrollment' => $this->formatEnrollment(
                    $linkedEnrollment
                ),
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    private function formatEnrollment(?Enrollment $enrollment): ?array
    {
        if (! $enrollment) {
            return null;
        }

        $enrollable = $enrollment->enrollable;

        /*
         * اشتراك بالمادة كاملة
         */
        if ($enrollable instanceof TeacherSubject) {
            return [
                'id' => $enrollment->id,
                'enrollable_id' => $enrollment->enrollable_id,
                'status' => $enrollment->status,

                'type' => 'teacher_subject',

                'subject' => [
                    'name' => $enrollable->subject?->name,
                ],

                'item' => null,
            ];
        }

        /*
         * اشتراك بوحدة محددة
         */
        if ($enrollable instanceof Unit) {
            return [
                'id' => $enrollment->id,
                'enrollable_id' => $enrollment->enrollable_id,
                'status' => $enrollment->status,

                'type' => 'unit',

                'subject' => [
                    'name' => $enrollable
                        ->teacherSubject
                        ?->subject
                        ?->name,
                ],

                'item' => [
                    'title' => $enrollable->title,
                ],
            ];
        }

        /*
         * اشتراك بمحتوى محدد
         */
        if ($enrollable instanceof Content) {
            return [
                'id' => $enrollment->id,
                'enrollable_id' => $enrollment->enrollable_id,
                'status' => $enrollment->status,

                'type' => 'content',

                'subject' => [
                    'name' => $enrollable
                        ->lesson
                        ?->unit
                        ?->teacherSubject
                        ?->subject
                        ?->name,
                ],

                'item' => [
                    'title' => $enrollable->title,
                ],
            ];
        }

        return [
            'id' => $enrollment->id,
            'enrollable_id' => $enrollment->enrollable_id,
            'status' => $enrollment->status,
            'type' => 'unknown',
            'subject' => null,
            'item' => null,
        ];
    }
}
```

## app/Http/Controllers/Api/V1/AvailableSessionController.php

قائمة جلسات المدرس المستقبلية scheduled الفردية الفارغة. فحص الأهلية والتعارض قبل pagination. from وto اختياريان. eligible_enrollment_ids للاختيار عند الحجز. لا ينشئ مواعيد تلقائياً ولا يحتاج معرف درس.

```php
<?php

namespace App\Http\Controllers\Api\V1;


use App\Models\Session;
use App\Models\Teacher;
use App\Services\SessionEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailableSessionController extends ApiController
{
    public function index(Request $request, Teacher $teacher): JsonResponse
    {
        $student = $request->user()->student;
        abort_unless($student && $student->status === 'active' && $request->user()->status === 'active', 403);

        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => array_merge(['sometimes', 'date'], $request->filled('from') ? ['after_or_equal:from'] : []),
        ]);
        $eligibility = app(SessionEligibility::class);
        $query = Session::query()->where('teacher_id', $teacher->id)
            ->where('type', 'INDIVIDUAL')->where('status', 'scheduled')->whereNull('enrollment_id')
            ->where('starts_at', '>', now())
            ->when(isset($filters['from']), fn ($q) => $q->where('starts_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('starts_at', '<=', $filters['to']))
            ->whereHas('teacher', fn ($q) => $q->where('status', 'active'));
        // Evaluate eligibility before pagination so every listed slot can be booked by this student.
        $ids = [];
        foreach ((clone $query)->cursor() as $session) {
            if ($eligibility->enrollments($student->id, $session)->exists()
                && ! $eligibility->conflicts($student->id, $session)->exists()) {
                $ids[] = $session->id;
            }
        }
        $page = $query->whereIn('id', $ids)->orderBy('starts_at')->orderBy('id')->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map(fn ($session) => [
            'id' => $session->id, 'teacher_id' => $teacher->id,
            'type' => 'individual', 'starts_at' => $session->starts_at, 'ends_at' => $session->ends_at,
            'status' => $session->status,
            'eligible_enrollment_ids' => $eligibility->enrollments($student->id, $session)->orderBy('id')->pluck('id'),
        ]));
        return $this->paginated($page);
    }
}
```

## app/Http/Controllers/Api/V1/SessionController.php

ملف موجود. حصر قائمة المدرس والتحقق من ملكيته، منع نقل روابط الجلسة، وحجز داخل transaction مع قفل الطالب والجلسة. يستخدم الأهلية وفحص التعارض عبر اشتراكات الطالب. أصل الحجز والإلغاء موجود سابقاً: الإلغاء يفرغ enrollment_id ويبقي scheduled.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Session;
use App\Services\SessionEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Session::query()->where('teacher_id', $request->user()->teacher?->id);

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $teacher = $request->user()->teacher;
        abort_unless($teacher && $teacher->status === 'active', 403);
        $validated = $request->validate([
            'teacher_id' => ['sometimes', 'integer', \Illuminate\Validation\Rule::in([$teacher->id])],
            'availability_id' => ['nullable', 'integer', 'exists:teacher_availabilities,id'],
            'type' => ['required', \Illuminate\Validation\Rule::in(['INDIVIDUAL', 'GROUP'])],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', \Illuminate\Validation\Rule::in(['scheduled', 'ongoing', 'completed', 'cancelled'])],
        ]);
        if (! empty($validated['group_id'])) {
            abort_unless(\App\Models\Group::whereKey($validated['group_id'])->whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $teacher->id))->exists(), 403);
        }
        if (! empty($validated['availability_id'])) {
            abort_unless(\App\Models\TeacherAvailability::whereKey($validated['availability_id'])->where('teacher_id', $teacher->id)->exists(), 403);
        }
        if (! empty($validated['enrollment_id'])) {
            abort_unless(Enrollment::whereKey($validated['enrollment_id'])->where('teacher_id', $teacher->id)->exists(), 403);
        }
        $validated['teacher_id'] = $teacher->id;
        $session = Session::create($validated);

        return $this->success($session, 'Session created successfully.', 201);
    }

    /**
     * Student booking endpoint from the SDD:
     * POST /api/v1/sessions/{id}/book
     *
     * The student comes from the Bearer token. For an INDIVIDUAL session, the
     * session is considered an available slot while enrollment_id is NULL.
     * Booking binds that slot to one active enrollment owned by the student.
     */
    public function book(Request $request, int $id): JsonResponse
    {
        $student = $request->user()?->student;

        if (! $student) {
            return response()->json([
                'status' => false,
                'message' => 'Student account not found.',
                'code' => 'STUDENT_PROFILE_NOT_FOUND',
            ], 403);
        }

        $validated = $request->validate([
            'enrollment_id' => ['nullable', 'integer', 'exists:enrollments,id'],
        ]);
        abort_unless($student->status === 'active' && $request->user()->status === 'active', 403);

        $result = DB::transaction(function () use ($student, $validated, $id) {
            \App\Models\Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $session = Session::query()->lockForUpdate()->findOrFail($id);

            if ($session->teacher?->status !== 'active' || $session->teacher->user?->status !== 'active') {
                return ['error' => ['The teacher is not available.', 'TEACHER_NOT_AVAILABLE', 409]];
            }


            if ($session->type !== 'INDIVIDUAL') {
                return ['error' => ['This endpoint books individual sessions only.', 'SESSION_TYPE_NOT_BOOKABLE', 422]];
            }

            if ($session->status !== 'scheduled') {
                return ['error' => ['Only scheduled sessions can be booked.', 'SESSION_NOT_AVAILABLE', 409]];
            }

            if ($session->starts_at !== null && $session->starts_at->lte(now())) {
                return ['error' => ['Past or started sessions cannot be booked.', 'SESSION_ALREADY_STARTED', 409]];
            }

            if ($session->enrollment_id !== null) {
                $existing = Enrollment::find($session->enrollment_id);
                $code = $existing && (int) $existing->student_id === (int) $student->id
                    ? 'ALREADY_BOOKED_BY_STUDENT'
                    : 'SESSION_ALREADY_BOOKED';

                return ['error' => ['This session is already booked.', $code, 409]];
            }

            $eligibility = app(SessionEligibility::class);
            $query = $eligibility->enrollments($student->id, $session);

            if (! empty($validated['enrollment_id'])) {
                $enrollment = (clone $query)->whereKey($validated['enrollment_id'])->first();
                if (! $enrollment) {
                    return ['error' => ['The selected enrollment is not an active enrollment for this student and teacher.', 'INVALID_ENROLLMENT', 422]];
                }
            } else {
                $matches = $query->orderBy('id')->limit(2)->get();
                if ($matches->isEmpty()) {
                    return ['error' => ['No active enrollment is available for this teacher.', 'NO_ACTIVE_ENROLLMENT', 422]];
                }
                if ($matches->count() > 1) {
                    return ['error' => ['More than one active enrollment matches this teacher. Send enrollment_id in the request body.', 'ENROLLMENT_SELECTION_REQUIRED', 422]];
                }
                $enrollment = $matches->first();
            }

            $hasConflict = $eligibility->conflicts($student->id, $session)->exists();

            if ($hasConflict) {
                return ['error' => ['The student already has another session that overlaps this time.', 'SESSION_TIME_CONFLICT', 409]];
            }

            $session->update([
                'enrollment_id' => $enrollment->id,
                'capacity' => 1,
            ]);

            return ['session' => $session->fresh()->load(['teacher.user:id,name,email', 'enrollment'])];
        });

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];
            return response()->json(['status' => false, 'message' => $message, 'code' => $code], $status);
        }

        return $this->success($result['session'], 'Session booked successfully.');
    }

    /**
     * Student cancellation endpoint from the SDD:
     * DELETE /api/v1/sessions/{id}/booking
     *
     * Cancelling a booking releases the INDIVIDUAL slot by clearing enrollment_id;
     * it does not delete the session and does not cancel the teacher's slot.
     */
    public function cancelBooking(Request $request, int $id): JsonResponse
    {
        $student = $request->user()?->student;

        if (! $student) {
            return response()->json([
                'status' => false,
                'message' => 'Student account not found.',
                'code' => 'STUDENT_PROFILE_NOT_FOUND',
            ], 403);
        }

        $result = DB::transaction(function () use ($student, $id) {
            $session = Session::query()->lockForUpdate()->findOrFail($id);

            if ($session->type !== 'INDIVIDUAL' || $session->enrollment_id === null) {
                return ['error' => ['This session has no student booking to cancel.', 'BOOKING_NOT_FOUND', 404]];
            }

            $enrollment = Enrollment::find($session->enrollment_id);
            if (! $enrollment || (int) $enrollment->student_id !== (int) $student->id) {
                return ['error' => ['This booking does not belong to the authenticated student.', 'BOOKING_FORBIDDEN', 403]];
            }

            if ($session->status !== 'scheduled') {
                return ['error' => ['Only scheduled bookings can be cancelled.', 'BOOKING_CANNOT_BE_CANCELLED', 409]];
            }

            if ($session->starts_at !== null && $session->starts_at->lte(now())) {
                return ['error' => ['A started or past session cannot be cancelled.', 'BOOKING_ALREADY_STARTED', 409]];
            }

            $session->update(['enrollment_id' => null]);

            return ['session' => $session->fresh()];
        });

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];
            return response()->json(['status' => false, 'message' => $message, 'code' => $code], $status);
        }

        return $this->success($result['session'], 'Session booking cancelled successfully.');
    }

    /**
     * Teacher cancellation endpoint:
     * POST /api/v1/sessions/{session}/cancel
     *
     * This cancels the SESSION itself, unlike cancelBooking() which only releases
     * a student's INDIVIDUAL booking. The enrollment/group links are preserved for
     * audit/history; the session status becomes "cancelled".
     */
    public function cancel(Request $request, Session $session): JsonResponse
    {
        $teacher = $request->user()?->teacher;

        if (! $teacher) {
            return response()->json([
                'status' => false,
                'message' => 'Teacher account not found.',
                'code' => 'TEACHER_PROFILE_NOT_FOUND',
            ], 403);
        }

        $result = DB::transaction(function () use ($teacher, $session) {
            $session = Session::query()
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ((int) $session->teacher_id !== (int) $teacher->id) {
                return ['error' => [
                    'This session does not belong to the authenticated teacher.',
                    'SESSION_FORBIDDEN',
                    403,
                ]];
            }

            if ($session->status === 'cancelled') {
                return ['session' => $session->fresh(), 'already_cancelled' => true];
            }

            if ($session->status === 'completed') {
                return ['error' => [
                    'A completed session cannot be cancelled.',
                    'SESSION_ALREADY_COMPLETED',
                    409,
                ]];
            }

            if ($session->status === 'ongoing') {
                return ['error' => [
                    'An ongoing session cannot be cancelled.',
                    'SESSION_ALREADY_STARTED',
                    409,
                ]];
            }

            if ($session->status !== 'scheduled') {
                return ['error' => [
                    'Only scheduled sessions can be cancelled.',
                    'SESSION_CANNOT_BE_CANCELLED',
                    409,
                ]];
            }

            if ($session->starts_at !== null && $session->starts_at->lte(now())) {
                return ['error' => [
                    'A started or past session cannot be cancelled.',
                    'SESSION_ALREADY_STARTED',
                    409,
                ]];
            }

            $session->update([
                'status' => 'cancelled',
            ]);

            return ['session' => $session->fresh(), 'already_cancelled' => false];
        });

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];

            return response()->json([
                'status' => false,
                'message' => $message,
                'code' => $code,
            ], $status);
        }

        return $this->success(
            $result['session'],
            $result['already_cancelled']
                ? 'Session is already cancelled.'
                : 'Session cancelled successfully.'
        );
    }

    public function show(Session $session): JsonResponse
    {
        return $this->success($session);
    }

    public function update(Request $request, Session $session): JsonResponse
    {
        abort_unless($session->teacher_id === $request->user()->teacher?->id, 403);
        if ($session->enrollment_id !== null || $session->group_id !== null) {
        }
        // Ownership and curriculum changes use session creation rather than reassignment.
        $request->validate(['teacher_id' => ['prohibited'], 'availability_id' => ['prohibited'], 'group_id' => ['prohibited'], 'enrollment_id' => ['prohibited']]);
        $session->update($request->validate([
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'availability_id' => ['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id'],
            'type' => ['sometimes', 'string', 'max:50'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'enrollment_id' => ['sometimes', 'nullable', 'integer', 'exists:enrollments,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($session->fresh(), 'Session updated successfully.');
    }

    public function destroy(Session $session): JsonResponse
    {
        $session->delete();

        return $this->success(message: 'Session deleted successfully.');
    }
}
```

## config/payments.php

عملة الدفع الافتراضية SYP، قابلة للتغيير عبر PAYMENT_CURRENCY.

```php
<?php

return [
    'currency' => env('PAYMENT_CURRENCY', 'SYP'),
];
```

## app/Http/Controllers/Api/V1/EnrollmentPaymentController.php

الطالب يقدم مرجع تحويل فقط؛ المبلغ من الاشتراك والحالة pending. المراجعة من مسؤول فعال: confirm يحول الدفع succeeded والاشتراك active بدون انتهاء، reject يجعل الدفع failed ويبقي الاشتراك pending. الأقفال بنفس الترتيب والتأكيد المكرر لا يغير التواريخ. تحقق التحويل الحقيقي وعدم استخدامه مرتين مسؤولية المراجعة اليدوية خارج النظام.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentPaymentController extends ApiController
{
    public function store(Request $request, Enrollment $enrollment): JsonResponse
    {
        $student = $request->user()->student;
        abort_unless($student && $student->status === 'active' && $request->user()->status === 'active'
            && $enrollment->student_id === $student->id, 403);
        $validated = $request->validate([
            'provider_reference' => ['required', 'string', 'max:255'],
        ]);
        return DB::transaction(function () use ($request, $enrollment, $validated) {
            $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            if ($enrollment->status !== 'pending' || (float) $enrollment->price <= 0) {
                return $this->businessError('Only a pending enrollment with a positive price can be paid.', 409);
            }
            $existing = Payment::where('payable_type', $enrollment->getMorphClass())->where('payable_id', $enrollment->id)
                ->whereIn('status', ['pending', 'succeeded'])->first();
            if ($existing) {
                return $this->success($existing, 'Payment request already exists.');
            }
            $payment = Payment::create([
                'payer_id' => $request->user()->id, 'payable_type' => $enrollment->getMorphClass(),
                'payable_id' => $enrollment->id, 'amount' => $enrollment->price,
                'currency' => config('payments.currency'), 'provider' => 'manual_transfer',
                'provider_reference' => $validated['provider_reference'], 'status' => 'pending',
            ]);
            return $this->success($payment, 'Transfer submitted for administrator verification.', 201);
        });
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->payer_id === $request->user()->id && $request->user()->status === 'active'
            && $request->user()->student?->status === 'active', 403);
        return $this->success($payment);
    }

    public function review(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403);
        $validated = $request->validate(['decision' => ['required', 'in:confirm,reject']]);
        return DB::transaction(function () use ($payment, $validated) {
            // Same locking order as submitting a payment: enrollment, then payment.
            abort_unless($payment->payable_type === (new Enrollment)->getMorphClass() && $payment->provider === 'manual_transfer', 422);
            $enrollment = Enrollment::whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'succeeded' && $validated['decision'] === 'confirm') {
                return $this->success(['payment' => $payment, 'enrollment' => $enrollment], 'Payment already confirmed.');
            }
            if ($payment->status !== 'pending' || $enrollment->status !== 'pending') {
                return $this->businessError('Payment and enrollment must both be pending.', 409);
            }
            if ($validated['decision'] === 'reject') {
                $payment->update(['status' => 'failed']);
            } else {
                if ($payment->amount !== $enrollment->price || $payment->payer_id !== $enrollment->student?->user_id) {
                    return $this->businessError('Payment amount or payer does not match the enrollment.', 409);
                }
                $payment->update(['status' => 'succeeded', 'paid_at' => now()]);
                $enrollment->update(['status' => 'active', 'starts_at' => now(), 'expires_at' => null]);
            }
            return $this->success(['payment' => $payment->fresh(), 'enrollment' => $enrollment->fresh()], 'Payment review completed.');
        });
    }
}
```

## app/Http/Controllers/Api/V1/PaymentController.php

CRUD موجود أصبح محمياً للمسؤول، مع منع إنشاء وتعديل دفعات الاشتراكات عبره لتجنب تجاوز التقديم والمراجعة.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->with(['payer:id,name,email,role,status']);

        $query->when($request->filled('payer_id'), fn ($q) => $q->where('payer_id', $request->integer('payer_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')))
            ->when($request->filled('provider_reference'), fn ($q) => $q->where('provider_reference', $request->string('provider_reference')));

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->input('payable_type') === (new \App\Models\Enrollment)->getMorphClass()) {
            return $this->businessError('Enrollment payments must use the student submission and administrator review endpoints.', 422);
        }
        $payment = Payment::create($request->validate([
            'payer_id' => ['required', 'integer', 'exists:users,id'],
            'payable_type' => ['required', 'string', 'max:255'],
            'payable_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['required', 'string', 'max:100'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['pending', 'succeeded', 'failed', 'refunded'])],
            'paid_at' => ['nullable', 'date'],
        ]));

        return $this->success($payment->load('payer:id,name,email,role,status'), 'Payment created successfully.', 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->success($payment->load('payer:id,name,email,role,status'));
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->payable_type === (new \App\Models\Enrollment)->getMorphClass()
            || $request->input('payable_type') === (new \App\Models\Enrollment)->getMorphClass()) {
            return $this->businessError('Enrollment payments must use the administrator review endpoint.', 422);
        }
        $payment->update($request->validate([
            'payer_id' => ['sometimes', 'integer', 'exists:users,id'],
            'payable_type' => ['sometimes', 'required', 'string', 'max:255'],
            'payable_id' => ['sometimes', 'integer', 'min:1'],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'provider' => ['sometimes', 'string', 'max:100'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['pending', 'succeeded', 'failed', 'refunded'])],
            'paid_at' => ['nullable', 'date'],
        ]));

        return $this->success($payment->fresh(), 'Payment updated successfully.');
    }

    public function destroy(Payment $payment): JsonResponse
    {
        if ($payment->status === 'succeeded') {
            return $this->businessError('Succeeded payments must not be deleted. Use refund/reversal business logic instead.', 409);
        }

        $payment->delete();

        return $this->success(message: 'Payment deleted successfully.');
    }
}
```

## tests/Feature/StudentSessionFlowTest.php

اختبارات الجلسات والملكية والمواعيد والأهلية والتعارض والحجز والإلغاء والدفع ومراجعته. حُذفت اختبارات الدرس والغرفة، واستبدل التحقق بملكية المدرس. الاختبارات على SQLite in-memory.

```php
<?php

namespace Tests\Feature;

use App\Models\{User, Student, Teacher, Subject, TeacherSubject, Unit, Lesson, Enrollment, Session, Group, GroupEnrollment, Payment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentSessionFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Student $student;
    private Teacher $teacher;
    private TeacherSubject $course;
    private Lesson $lesson;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $this->user->id, 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->course = TeacherSubject::create(['teacher_id' => $this->teacher->id, 'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id, 'price' => 100, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $this->course->id, 'title' => 'Algebra', 'status' => 'published']);
        $this->lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Equations', 'status' => 'published']);
        $this->enrollment = Enrollment::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'enrollable_type' => TeacherSubject::class, 'enrollable_id' => $this->course->id, 'price' => 100, 'status' => 'active']);
        Sanctum::actingAs($this->user);
    }

    private function makeSession(array $extra = []): Session
    {
        return Session::create(array_merge(['teacher_id' => $this->teacher->id,
            'type' => 'INDIVIDUAL', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled'], $extra));
    }

    private function slots(): string
    {
        return '/api/v1/teachers/'.$this->teacher->id.'/available-sessions';
    }

    public function test_bookings_include_only_own_upcoming_sessions_and_group_enrollment(): void
    {
        $own = $this->makeSession(['enrollment_id' => $this->enrollment->id]);
        $this->makeSession(['enrollment_id' => $this->enrollment->id, 'status' => 'cancelled']);
        $this->makeSession(['enrollment_id' => $this->enrollment->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subHours(23)]);
        $this->makeSession();
        $group = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'Test', 'capacity' => 10, 'status' => 'active']);
        GroupEnrollment::create(['group_id' => $group->id, 'enrollment_id' => $this->enrollment->id, 'status' => 'active']);
        $groupSession = $this->makeSession(['type' => 'GROUP', 'group_id' => $group->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
        $this->getJson('/api/v1/student/bookings?student_id=999')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.0.teacher.id', $this->teacher->id)
            ->assertJsonPath('data.0.enrollment.id', $this->enrollment->id)
            ->assertJsonPath('data.1.id', $groupSession->id)->assertJsonPath('data.1.enrollment.id', $this->enrollment->id);
    }

    public function test_available_booking_cancellation_and_expiration(): void
    {
        $slot = $this->makeSession();
        $this->makeSession(['status' => 'cancelled']);
        $this->getJson($this->slots())->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $slot->id);
        $this->getJson($this->slots().'?to='.urlencode(now()->addWeek()->toISOString()))->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/sessions/'.$slot->id.'/book', ['enrollment_id' => $this->enrollment->id])->assertOk();
        $this->getJson($this->slots())->assertJsonPath('meta.total', 0);
        $this->deleteJson('/api/v1/sessions/'.$slot->id.'/booking')->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.enrollment_id', null);
        $this->getJson($this->slots())->assertJsonPath('meta.total', 1);
        $this->enrollment->update(['expires_at' => now()->addHour()]);
        $this->getJson($this->slots())->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/sessions/'.$slot->id.'/book', ['enrollment_id' => $this->enrollment->id])->assertUnprocessable();
    }

    public function test_booking_rejects_overlap_across_different_enrollments(): void
    {
        $other = $this->enrollment->replicate(); $other->save();
        $this->makeSession(['enrollment_id' => $other->id]);
        $slot = $this->makeSession();
        $this->getJson($this->slots())->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/sessions/'.$slot->id.'/book', ['enrollment_id' => $this->enrollment->id])
            ->assertConflict()->assertJsonPath('code', 'SESSION_TIME_CONFLICT');
    }

    public function test_manual_payment_requires_admin_and_activates_once(): void
    {
        $this->enrollment->update(['status' => 'pending']);
        $url = '/api/v1/student/enrollments/'.$this->enrollment->id.'/payments';
        $payment = $this->postJson($url, ['provider_reference' => 'TRANSFER-100', 'amount' => 1, 'status' => 'succeeded'])
            ->assertCreated()->assertJsonPath('data.amount', '100.00')->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson($url, ['provider_reference' => 'TRANSFER-100'])->assertOk()->assertJsonPath('data.id', $payment);
        $this->assertSame('pending', $this->enrollment->fresh()->status);
        $review = '/api/v1/admin/payments/'.$payment.'/review';
        $this->postJson($review, ['decision' => 'confirm'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin', 'status' => 'active']));
        $this->postJson($review, ['decision' => 'confirm'])->assertOk()->assertJsonPath('data.enrollment.status', 'active')->assertJsonPath('data.enrollment.expires_at', null);
        $paidAt = Payment::find($payment)->paid_at->toISOString();
        $this->travel(1)->hours();
        $this->postJson($review, ['decision' => 'confirm'])->assertOk();
        $this->assertSame($paidAt, Payment::find($payment)->paid_at->toISOString());
        $this->patchJson('/api/v1/payments/'.$payment, ['status' => 'pending'])->assertUnprocessable();
    }

    public function test_payment_rejection_allows_retry_and_other_students_cannot_access(): void
    {
        $this->enrollment->update(['status' => 'pending']);
        $url = '/api/v1/student/enrollments/'.$this->enrollment->id.'/payments';
        $id = $this->postJson($url, ['provider_reference' => 'FIRST'])->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin', 'status' => 'active']));
        $this->postJson('/api/v1/admin/payments/'.$id.'/review', ['decision' => 'reject'])->assertOk()->assertJsonPath('data.payment.status', 'failed');
        Sanctum::actingAs($this->user);
        $this->postJson($url, ['provider_reference' => 'SECOND'])->assertCreated();
        $other = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/student/payments/'.$id)->assertForbidden();
        $this->postJson($url, ['provider_reference' => 'THIRD'])->assertForbidden();
    }

    public function test_teacher_creates_and_lists_only_their_sessions(): void
    {
        Sanctum::actingAs($this->teacher->user);
        $body = ['type' => 'INDIVIDUAL',
            'starts_at' => now()->addDays(5)->toISOString(), 'ends_at' => now()->addDays(5)->addHour()->toISOString()];
        $id = $this->postJson('/api/v1/teacher/sessions', $body)->assertCreated()->assertJsonPath('data.teacher_id', $this->teacher->id)->json('data.id');
        $this->getJson('/api/v1/teacher/sessions')->assertOk()->assertJsonPath('meta.total', 1);
        $other = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/teacher/sessions', array_merge($body, ['teacher_id' => $this->teacher->id]))->assertUnprocessable();
        $this->getJson('/api/v1/teacher/sessions')->assertJsonPath('meta.total', 0);
    }


}
```

## المسارات الحالية المرتبطة بالجزء الأول

الطالب auth:sanctum وrole:student، المدرس role:teacher، والمسؤول role:company_admin. بادئات ملفات student وteacher كما يركبها bootstrap/app.php.

### routes/student.php

```php
Route::get('/teachers/{teacher}/available-sessions', [\App\Http\Controllers\Api\V1\AvailableSessionController::class, 'index']);
Route::get('/student/bookings', [StudentBookingController::class, 'index']);
Route::post('/student/enrollments/{enrollment}/payments', [\App\Http\Controllers\Api\V1\EnrollmentPaymentController::class, 'store']);
Route::get('/student/payments/{payment}', [\App\Http\Controllers\Api\V1\EnrollmentPaymentController::class, 'show']);
Route::post('/sessions/{id}/book', [SessionController::class, 'book']);
Route::delete('/sessions/{id}/booking', [SessionController::class, 'cancelBooking']);
```

### routes/teacher.php

```php
Route::get('/sessions', [SessionController::class, 'index']);
Route::post('/sessions', [SessionController::class, 'store']);
Route::patch('/sessions/{session}', [SessionController::class, 'update']);
```

### routes/api.php

```php
Route::post('/payments/{payment}/review', [\App\Http\Controllers\Api\V1\EnrollmentPaymentController::class, 'review']);
Route::apiResource('v1/payments', PaymentController::class)
    ->middleware(['auth:sanctum', 'role:company_admin']);
```

## إصلاحات الاختبارات السابقة

AdminUserStatusTest يحدد role:teacher لاختبار عقد الاستجابة العام. TeacherContentAssetTest يضبط filesystems.media_disk=local كي يطابق القرص المزيف. تعديلات اختبار فقط.

```diff
diff --git a/tests/Feature/AdminUserStatusTest.php b/tests/Feature/AdminUserStatusTest.php
index e1dde43..26fe04e 100644
--- a/tests/Feature/AdminUserStatusTest.php
+++ b/tests/Feature/AdminUserStatusTest.php
@@ -14,7 +14,8 @@ class AdminUserStatusTest extends TestCase
     public function test_company_admin_can_suspend_and_activate_a_user(): void
     {
         $admin = User::factory()->create(['role' => 'company_admin']);
-        $user = User::factory()->create(['status' => 'active']);
+        // This test covers the generic response; student suspension has its own contract.
+        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
 
         Sanctum::actingAs($admin);
 
diff --git a/tests/Feature/TeacherContentAssetTest.php b/tests/Feature/TeacherContentAssetTest.php
index a157948..2a6801c 100644
--- a/tests/Feature/TeacherContentAssetTest.php
+++ b/tests/Feature/TeacherContentAssetTest.php
@@ -19,6 +19,12 @@ class TeacherContentAssetTest extends TestCase
 {
     use RefreshDatabase;
 
+    protected function setUp(): void
+    {
+        parent::setUp();
+        config(['filesystems.media_disk' => 'local']);
+    }
+
     public function test_teacher_can_upload_list_and_delete_a_file_for_owned_content(): void
     {
         Storage::fake('local');
```
