<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurriculumBrowseController extends ApiController
{
    public function lessonContents(Lesson $lesson): JsonResponse
    {
        abort_unless($lesson->status === 'published' && $lesson->unit?->status === 'published'
            && $lesson->unit?->teacherSubject?->status === 'active'
            && $lesson->unit?->teacherSubject?->subject?->status === 'active'
            && $lesson->unit?->teacherSubject?->teacher?->status === 'active'
            && $lesson->unit?->teacherSubject?->teacher?->user?->status === 'active', 404);
        $contents = $lesson->contents()
            ->where('status', 'published')
            ->withCount(['assets', 'quizzes' => fn ($q) => $q->where('status', 'published')])->with(['quizzes' => fn ($q) => $q->where('status', 'published')->select('id', 'content_id', 'status', 'attempts_allowed', 'duration_minutes')])
            ->with(['assets' => fn ($q) => $q->where('status', 'active')->with('videoAsset')])
            ->orderBy('order_no')
            ->orderBy('id')
            ->get();

        return $this->success($contents->map(function ($content) {
            $duration = $content->assets->sortByDesc('id')->first(fn ($asset) => $asset->videoAsset !== null)?->videoAsset?->duration_seconds;
            $payload = $content->toArray();
            unset($payload['assets']);
            $payload['duration_seconds'] = $duration;

            return $payload;
        }), 'Lesson contents retrieved successfully.');
    }

    public function show(Request $request, Content $content)
    {
        $this->authorize('access', $content);

        return $this->success($content->load(['quizzes' => fn ($q) => $q->where('status', 'published')->select('id', 'content_id', 'status', 'attempts_allowed', 'duration_minutes')]));
    }

    public function quizzes(Request $request, Content $content)
    {
        $this->authorize('access', $content);

        return $this->success($content->quizzes()->where('status', 'published')
            ->orderBy('id')->get(['id', 'content_id', 'status', 'attempts_allowed', 'duration_minutes']));
    }
}
