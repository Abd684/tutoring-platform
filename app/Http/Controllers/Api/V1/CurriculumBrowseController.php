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
            ->orderBy('order_no')
            ->orderBy('id')
            ->get();

        return $this->success($contents, 'Lesson contents retrieved successfully.');
    }
}
