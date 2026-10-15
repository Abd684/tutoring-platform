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
        $this->authorize('accessStudent', \App\Models\Enrollment::class);

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
