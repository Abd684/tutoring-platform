<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Subject::query()->select(['id', 'name', 'description', 'status']);

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'));

        return $this->paginated($query->orderBy('name')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $subject = Subject::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($subject, 'Subject created successfully.', 201);
    }

    public function show(Subject $subject): JsonResponse
    {
        return $this->success($subject->loadCount('teacherSubjects'));
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $subject->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($subject->fresh(), 'Subject updated successfully.');
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $subject->delete();

        return $this->success(message: 'Subject deleted successfully.');
    }
}
