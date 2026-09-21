<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Student::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $student = Student::create($request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'grade' => ['nullable', 'string', 'max:100'],
            'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($student, 'Student created successfully.', 201);
    }

    public function show(Student $student): JsonResponse
    {
        return $this->success($student);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $student->update($request->validate([
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'grade' => ['sometimes', 'nullable', 'string', 'max:100'],
            'school_id' => ['sometimes', 'nullable', 'integer', 'exists:schools,id'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($student->fresh(), 'Student updated successfully.');
    }

    public function destroy(Student $student): JsonResponse
    {
        $student->delete();

        return $this->success(message: 'Student deleted successfully.');
    }
}
