<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Unit::query()->with(['teacherSubject.subject:id,name']);

        $query->when($request->filled('teacher_subject_id'), fn ($q) => $q->where('teacher_subject_id', $request->integer('teacher_subject_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'));

        return $this->paginated($query->orderBy('order_no')->orderBy('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $unit = Unit::create($request->validate([
            'teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ]));

        return $this->success($unit, 'Unit created successfully.', 201);
    }

    public function show(Unit $unit): JsonResponse
    {
        return $this->success($unit->load('teacherSubject.subject:id,name')->loadCount('lessons'));
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $unit->update($request->validate([
            'teacher_subject_id' => ['sometimes', 'integer', 'exists:teacher_subjects,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ]));

        return $this->success($unit->fresh(), 'Unit updated successfully.');
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $unit->delete();

        return $this->success(message: 'Unit deleted successfully.');
    }
}
