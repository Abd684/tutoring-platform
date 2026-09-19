<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Teacher::query()
            ->select(['id', 'user_id', 'bio', 'specialization', 'status'])
            ->with(['user:id,name,phone,email,role,status']);

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('specialization'), fn ($q) => $q->where('specialization', 'like', '%'.$request->string('specialization').'%'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = (string) $request->string('search');
                $q->where(function ($inner) use ($search) {
                    $inner->where('specialization', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                });
            });

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'teacher')),
                Rule::unique('teachers', 'user_id'),
            ],
            'bio' => ['nullable', 'string'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['pending_review', 'active', 'suspended'])],
        ]);

        $teacher = Teacher::create($validated)->load('user:id,name,phone,email,role,status');

        return $this->success($teacher, 'Teacher created successfully.', 201);
    }

    public function show(Teacher $teacher): JsonResponse
    {
        return $this->success($teacher->load([
            'user:id,name,phone,email,role,status',
            'teacherSubjects.subject:id,name,status',
        ]));
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'sometimes',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'teacher')),
                Rule::unique('teachers', 'user_id')->ignore($teacher->id),
            ],
            'bio' => ['nullable', 'string'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['pending_review', 'active', 'suspended'])],
        ]);

        $teacher->update($validated);

        return $this->success($teacher->fresh()->load('user:id,name,phone,email,role,status'), 'Teacher updated successfully.');
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $teacher->delete();

        return $this->success(message: 'Teacher deleted successfully.');
    }
}
