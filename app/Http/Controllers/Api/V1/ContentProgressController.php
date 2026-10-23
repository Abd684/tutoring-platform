<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\ContentProgress;
use App\Models\TeacherSubject;
use App\Services\ContentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentProgressController extends ApiController
{
    public function complete(Request $request, Content $content, ContentAccess $access): JsonResponse
    {
        $student = $request->user()->student;
        abort_if($request->user()->status !== 'active' || ! $student || $student->status !== 'active', 403, 'Active student profile required.');
        abort_unless($content->status === 'published' && $access->allows($student, $content), 404);
        // The unique key makes repeated and concurrent completion requests safe.
        $progress = ContentProgress::firstOrCreate(
            ['student_id' => $student->id, 'content_id' => $content->id],
            ['completed_at' => now()],
        );

        return $this->success([
            'content_id' => $content->id,
            'completed' => true,
            'completed_at' => $progress->completed_at->toIso8601String(),
        ]);
    }

    public function show(Request $request, TeacherSubject $teacherSubject, ContentAccess $access): JsonResponse
    {
        $student = $request->user()->student;
        abort_if($request->user()->status !== 'active' || ! $student || $student->status !== 'active', 403, 'Active student profile required.');
        abort_unless($access->allowsOffering($student, $teacherSubject), 404);
        $contents = Content::query()->where('status', 'published')
            ->whereHas('lesson.unit', fn ($q) => $q->where('teacher_subject_id', $teacherSubject->id));
        $total = (clone $contents)->count();
        $completedIds = ContentProgress::query()->where('student_id', $student->id)
            ->whereIn('content_id', $contents->select('id'))->orderBy('content_id')->pluck('content_id');
        $completed = $completedIds->count();

        return $this->success([
            'teacher_subject_id' => $teacherSubject->id,
            'total_contents' => $total,
            'completed_contents' => $completed,
            'progress_percentage' => $total ? round($completed * 100 / $total, 2) : 0,
            'completed_content_ids' => $completedIds,
        ]);
    }
}
