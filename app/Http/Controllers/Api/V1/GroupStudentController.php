<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\GroupMembershipRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupStudentController extends ApiController
{
    public function addToTeacherGroup(Request $request, int $id)
    {
        $data = $request->validate(['student_id' => ['required', 'integer', 'exists:students,id'], 'enrollment_id' => ['prohibited']]);
        $group = Group::findOrFail($id);
        $this->authorize('manage', $group);

        return DB::transaction(function () use ($data, $group) {
            Teacher::whereKey($group->teacherSubject->teacher_id)->lockForUpdate()->firstOrFail();
            $group = Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $student = Student::whereKey($data['student_id'])->lockForUpdate()->firstOrFail();
            app(GroupMembershipRules::class)->validate($group, $student);
            abort_unless(in_array($group->status, ['active', 'forming'], true), 409, 'Group is closed.');
            abort_if($group->groupStudents()->where('student_id', $student->id)->where('status', 'active')->exists(), 409, 'Student is already a member.');
            abort_if($group->groupStudents()->where('status', 'active')->count() >= $group->capacity, 409, 'Group is full.');
            $member = GroupStudent::updateOrCreate(['group_id' => $group->id, 'student_id' => $student->id], ['status' => 'active', 'joined_at' => now(), 'left_at' => null]);

            return $this->success($member, 'Student added to group.', 201);
        }, 3);
    }

    public function removeFromTeacherGroup(Request $request, int $id, int $student)
    {
        $group = Group::findOrFail($id);
        $this->authorize('manage', $group);

        return DB::transaction(function () use ($group, $student) {
            Teacher::whereKey($group->teacherSubject->teacher_id)->lockForUpdate()->firstOrFail();
            Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $member = $group->groupStudents()->where('student_id', $student)->lockForUpdate()->firstOrFail();
            $member->update(['status' => 'removed', 'left_at' => now()]);

            return $this->success($member, 'Student removed from group.');
        });
    }
}
