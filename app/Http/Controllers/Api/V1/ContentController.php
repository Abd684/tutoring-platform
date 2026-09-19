<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Content::query()->with(['lesson:id,unit_id,title,status']);

        $query->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'));

        return $this->paginated($query->orderBy('order_no')->orderBy('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $content = Content::create($request->validate([
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
            'type' => ['required', Rule::in(['video', 'pdf', 'quiz', 'link'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'published_at' => ['nullable', 'date'],
        ]));

        return $this->success($content, 'Content created successfully.', 201);
    }

    public function show(Content $content): JsonResponse
    {
        return $this->success($content->load([
            'lesson:id,unit_id,title,status',
            'assets:id,content_id,disk,mime_type,file_size,encryption_type,encryption_status,status',
        ])->loadCount(['assets', 'quizzes']));
    }

    public function update(Request $request, Content $content): JsonResponse
    {
        $content->update($request->validate([
            'lesson_id' => ['sometimes', 'integer', 'exists:lessons,id'],
            'type' => ['sometimes', Rule::in(['video', 'pdf', 'quiz', 'link'])],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'published_at' => ['nullable', 'date'],
        ]));

        return $this->success($content->fresh(), 'Content updated successfully.');
    }

    public function destroy(Content $content): JsonResponse
    {
        $content->delete();

        return $this->success(message: 'Content deleted successfully.');
    }
}
