<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeaturedTeacherController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Teacher::query()->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->with('user:id,name,avatar_path')
            ->select('teachers.*')->selectSub(
                Enrollment::query()->selectRaw('COUNT(DISTINCT student_id)')
                    ->whereColumn('teacher_id', 'teachers.id')->where('status', 'active')
                    ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                'active_students_count',
            );
        $paginator = $query->orderByDesc('active_students_count')->orderBy('teachers.id')
            ->paginate($this->perPage($request));
        $paginator->through(fn (Teacher $teacher): array => [
            'id' => $teacher->id,
            'name' => $teacher->user->name,
            'specialization' => $teacher->specialization,
            'avatar_url' => $teacher->user->avatar_url,
            'active_students_count' => (int) $teacher->active_students_count,
        ]);

        return $this->paginated($paginator);
    }
}
