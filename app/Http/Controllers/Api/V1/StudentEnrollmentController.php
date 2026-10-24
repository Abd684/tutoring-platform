<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\PaidBooking;
use App\Models\Student;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Services\ContentAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentEnrollmentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('accessStudent', Enrollment::class);

        return $this->listEnrollments($request);
    }

    public function current(Request $request): JsonResponse
    {
        $this->authorize('accessStudent', Enrollment::class);

        return $this->listEnrollments($request, true);
    }

    private function listEnrollments(Request $request, bool $currentOnly = false): JsonResponse
    {
        $student = $this->currentStudent($request);

        if (! $student) {
            return response()->json([
                'status' => false,
                'message' => 'Student profile not found.',
            ], 404);
        }

        $paginator = Enrollment::query()
            ->where('student_id', $student->id)
            ->when($currentOnly, function ($query): void {
                $query->whereIn('status', ['pending', 'active'])
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            })
            ->with([
                'teacher.user:id,name,email',
                'enrollable' => function (MorphTo $morphTo): void {
                    $morphTo->morphWith([
                        TeacherSubject::class => ['subject:id,name'],
                        Unit::class => ['teacherSubject.subject:id,name'],
                        Content::class => ['lesson.unit.teacherSubject.subject:id,name'],
                    ]);
                },
            ])
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $data = collect($paginator->items())
            ->map(fn (Enrollment $enrollment) => $this->enrollmentPayload($enrollment))
            ->values();

        return response()->json([
            'status' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function enrollTeacherSubject(Request $request, int $id): JsonResponse
    {
        $this->authorize('accessStudent', Enrollment::class);

        $student = $this->currentStudent($request);

        if (! $student) {
            return $this->studentNotFound();
        }

        $teacherSubject = TeacherSubject::query()
            ->with(['teacher', 'subject:id,name'])
            ->findOrFail($id);

        if ($teacherSubject->status !== 'active') {
            return $this->purchaseUnavailable('This teacher subject is not available for purchase.');
        }

        $existing = $this->existingEnrollment(
            $student,
            $teacherSubject
        );

        if ($existing) {
            return $this->alreadyEnrolled($existing);
        }

        $enrollment = $teacherSubject->enrollments()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacherSubject->teacher_id,
            'price' => $teacherSubject->price,
            'status' => 'pending',
        ]);

        $enrollment->load('teacher.user:id,name,email');
        $enrollment->setRelation('enrollable', $teacherSubject);

        return response()->json([
            'status' => true,
            'message' => 'Subject purchase request created successfully.',
            'data' => $this->enrollmentPayload($enrollment),
        ], 201);
    }

    public function enrollUnit(Request $request, int $id): JsonResponse
    {
        $this->authorize('accessStudent', Enrollment::class);

        $student = $this->currentStudent($request);

        if (! $student) {
            return $this->studentNotFound();
        }

        $unit = Unit::query()
            ->with(['teacherSubject.teacher', 'teacherSubject.subject:id,name'])
            ->findOrFail($id);

        if ($unit->status !== 'published' || $unit->teacherSubject?->status !== 'active') {
            return $this->purchaseUnavailable('This unit is not available for purchase.');
        }

        if ($unit->price === null) {
            return $this->priceNotSet('The price for this unit has not been set yet.');
        }

        $coveredBySubject = $this->coveringEnrollment(
            $student,
            $unit->teacherSubject
        );

        if ($coveredBySubject) {
            return response()->json([
                'status' => false,
                'message' => 'This unit is already covered by the student subject enrollment.',
                'code' => 'ALREADY_COVERED_BY_SUBJECT',
            ], 409);
        }

        $existing = $this->existingEnrollment($student, $unit);

        if ($existing) {
            return $this->alreadyEnrolled($existing);
        }

        $enrollment = $unit->enrollments()->create([
            'student_id' => $student->id,
            'teacher_id' => $unit->teacherSubject->teacher_id,
            'price' => $unit->price,
            'status' => 'pending',
        ]);

        $enrollment->load('teacher.user:id,name,email');
        $enrollment->setRelation('enrollable', $unit);

        return response()->json([
            'status' => true,
            'message' => 'Unit purchase request created successfully.',
            'data' => $this->enrollmentPayload($enrollment),
        ], 201);
    }

    public function enrollContent(Request $request, int $id): JsonResponse
    {
        $this->authorize('accessStudent', Enrollment::class);

        $student = $this->currentStudent($request);

        if (! $student) {
            return $this->studentNotFound();
        }

        $content = Content::query()
            ->with(['lesson.unit.teacherSubject.teacher', 'lesson.unit.teacherSubject.subject:id,name'])
            ->findOrFail($id);

        $lesson = $content->lesson;
        $unit = $lesson?->unit;
        $teacherSubject = $unit?->teacherSubject;

        if (
            $content->status !== 'published'
            || ! $lesson
            || $lesson->status !== 'published'
            || ! $unit
            || $unit->status !== 'published'
            || ! $teacherSubject
            || $teacherSubject->status !== 'active'
        ) {
            return $this->purchaseUnavailable('This content is not available for purchase.');
        }

        if ($content->price === null) {
            return $this->priceNotSet('The price for this content has not been set yet.');
        }

        $coveredBySubject = $this->coveringEnrollment(
            $student,
            $teacherSubject
        );

        if ($coveredBySubject) {
            return response()->json([
                'status' => false,
                'message' => 'This content is already covered by the student subject enrollment.',
                'code' => 'ALREADY_COVERED_BY_SUBJECT',
            ], 409);
        }

        $coveredByUnit = $this->coveringEnrollment($student, $unit);

        if ($coveredByUnit) {
            return response()->json([
                'status' => false,
                'message' => 'This content is already covered by the student unit enrollment.',
                'code' => 'ALREADY_COVERED_BY_UNIT',
            ], 409);
        }

        $existing = $this->existingEnrollment($student, $content);

        if ($existing) {
            return $this->alreadyEnrolled($existing);
        }

        $enrollment = $content->enrollments()->create([
            'student_id' => $student->id,
            'teacher_id' => $teacherSubject->teacher_id,
            'price' => $content->price,
            'status' => 'pending',
        ]);

        $enrollment->load('teacher.user:id,name,email');
        $enrollment->setRelation('enrollable', $content);

        return response()->json([
            'status' => true,
            'message' => 'Content purchase request created successfully.',
            'data' => $this->enrollmentPayload($enrollment),
        ], 201);
    }

    private function currentStudent(Request $request): ?Student
    {
        return $request->user()?->student;
    }

    private function coveringEnrollment(Student $student, Model $enrollable): ?Enrollment
    {
        return app(ContentAccess::class)->enrollments($student)
            ->where('enrollable_type', $enrollable->getMorphClass())
            ->where('enrollable_id', $enrollable->getKey())->first();
    }

    private function existingEnrollment(
        Student $student,
        Model $enrollable
    ): ?Enrollment {
        return Enrollment::query()
            ->where('student_id', $student->id)
            ->where('enrollable_type', $enrollable->getMorphClass())
            ->where('enrollable_id', $enrollable->getKey())
            ->whereIn('status', ['pending', 'active'])
            ->latest('id')
            ->first();
    }

    private function enrollmentPayload(Enrollment $enrollment): array
    {
        $type = match ($enrollment->enrollable_type) {
            (new TeacherSubject)->getMorphClass() => 'subject',
            (new Unit)->getMorphClass() => 'unit',
            (new Content)->getMorphClass() => 'content',
            'paid_booking' => 'paid_booking',
            default => 'unknown',
        };

        $item = $enrollment->enrollable;
        $title = null;
        $subjectName = null;

        if ($item instanceof PaidBooking) {
            $title = 'درس مدفوع';
        } elseif ($item instanceof TeacherSubject) {
            $title = $item->subject?->name;
            $subjectName = $item->subject?->name;
        } elseif ($item instanceof Unit) {
            $title = $item->title;
            $subjectName = $item->teacherSubject?->subject?->name;
        } elseif ($item instanceof Content) {
            $title = $item->title;
            $subjectName = $item->lesson?->unit?->teacherSubject?->subject?->name;
        }

        return [
            'id' => $enrollment->id,
            'type' => $type,
            'item' => [
                'id' => $enrollment->enrollable_id,
                'title' => $title,
                'subject_name' => $subjectName,
            ],
            'teacher' => [
                'id' => $enrollment->teacher_id,
                'name' => $enrollment->teacher?->user?->name,
            ],
            'price' => $enrollment->price,
            'status' => $enrollment->status,
            'starts_at' => $enrollment->starts_at?->toDateTimeString(),
            'expires_at' => $enrollment->expires_at?->toDateTimeString(),
        ];
    }

    private function studentNotFound(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Student profile not found.',
        ], 404);
    }

    private function priceNotSet(string $message): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'code' => 'PRICE_NOT_SET',
        ], 409);
    }

    private function purchaseUnavailable(string $message): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'code' => 'PURCHASE_UNAVAILABLE',
        ], 409);
    }

    private function alreadyEnrolled(Enrollment $enrollment): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'The student already has a pending or active enrollment for this item.',
            'code' => 'ALREADY_ENROLLED',
            'enrollment_id' => $enrollment->id,
        ], 409);
    }
}
