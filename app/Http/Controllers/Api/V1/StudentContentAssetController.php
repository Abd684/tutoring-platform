<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentContentAssetController extends ApiController
{
    public function playbackToken(Request $request, int $id): JsonResponse
    {
        $student = $request->user()->student;
        abort_if($student === null || $student->status !== 'active', 403, 'Active student profile required.');

        $content = Content::query()->with('lesson.unit.teacherSubject')->findOrFail($id);
        $unit = $content?->lesson?->unit;
        $teacherSubject = $unit?->teacherSubject;

        abort_unless($content !== null && $unit !== null && $teacherSubject !== null, 404);
        abort_unless($this->hasActiveEnrollment($student, $content, $unit, $teacherSubject), 404);

        $contentAsset = $content->assets()->where('status', 'active')->latest('id')->firstOrFail();
        abort_unless(Storage::disk($contentAsset->disk)->exists($contentAsset->storage_key), 404);

        $expiresAt = now()->addMinutes(5);
        $playbackUrl = URL::temporarySignedRoute('student.content.play', $expiresAt, [
            'contentAsset' => $contentAsset->id,
            'student' => $student->id,
        ]);

        return $this->success([
            'playback_url' => $playbackUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 'Playback token created successfully.');
    }

    public function play(Request $request, ContentAsset $contentAsset): StreamedResponse
    {
        $student = $request->user()->student;
        abort_if($student === null || $student->status !== 'active', 403, 'Active student profile required.');
        abort_unless((int) $request->query('student') === (int) $student->id, 403);

        $contentAsset->loadMissing('content.lesson.unit.teacherSubject');
        $content = $contentAsset->content;
        $unit = $content?->lesson?->unit;
        $teacherSubject = $unit?->teacherSubject;

        abort_unless($content !== null && $unit !== null && $teacherSubject !== null, 404);
        abort_unless($this->hasActiveEnrollment($student, $content, $unit, $teacherSubject), 404);

        $disk = Storage::disk($contentAsset->disk);
        abort_unless($contentAsset->status === 'active' && $disk->exists($contentAsset->storage_key), 404);

        return $disk->response(
            $contentAsset->storage_key,
            null,
            ['Content-Type' => $contentAsset->mime_type ?: 'application/octet-stream'],
            'inline',
        );
    }

    private function hasActiveEnrollment(
        Student $student,
        Content $content,
        Unit $unit,
        TeacherSubject $teacherSubject,
    ): bool {
        return Enrollment::query()
            ->where('student_id', $student->id)
            ->where('teacher_id', $teacherSubject->teacher_id)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query
                ->where(fn ($scope) => $scope
                    ->where('enrollable_type', Content::class)
                    ->where('enrollable_id', $content->id))
                ->orWhere(fn ($scope) => $scope
                    ->where('enrollable_type', Unit::class)
                    ->where('enrollable_id', $unit->id))
                ->orWhere(fn ($scope) => $scope
                    ->where('enrollable_type', TeacherSubject::class)
                    ->where('enrollable_id', $teacherSubject->id)))
            ->exists();
    }
}
