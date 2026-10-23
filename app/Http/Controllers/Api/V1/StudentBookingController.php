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
        $this->authorize('accessStudent', Enrollment::class);

        $student = Student::query()
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $student) {
            return response()->json([
                'message' => 'Student account not found.',
            ], 404);
        }
        abort_unless($student->status === 'active' && $request->user()->status === 'active', 403);
        $request->validate(['scope' => ['sometimes', 'in:upcoming,history,all']]);
        $scope = $request->input('scope', 'upcoming');

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
            ->when($scope === 'upcoming', fn ($q) => $q->where('starts_at', '>=', now())->where('status', 'scheduled'))
            ->when($scope === 'history', fn ($q) => $q->where(fn ($q) => $q->where('ends_at', '<=', now())->orWhereIn('status', ['completed', 'cancelled'])))

            // لا نعيد الجلسات الملغاة أو المنتهية

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

    public function show(Request $request, Session $session): JsonResponse
    {
        $this->authorize('accessStudent', Enrollment::class);
        $this->authorize('studentView', $session);

        return $this->success($session->only(['id', 'teacher_id', 'type', 'starts_at', 'ends_at', 'status']));
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
