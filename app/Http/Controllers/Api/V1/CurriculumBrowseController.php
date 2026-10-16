<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Lesson;
use Illuminate\Http\JsonResponse;

class CurriculumBrowseController extends ApiController
{
    public function lessonContents(Lesson $lesson): JsonResponse
    {
        $contents = $lesson->contents()
            ->where('status', 'published')
            ->withCount(['assets', 'quizzes'])
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
}
