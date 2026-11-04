<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Teacher;
use App\Services\LegacyTeaching;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyGroupMembersController extends ApiController
{
    public function store(Request $request, int $id)
    {
        $data = $request->validate(['enrollment_id' => ['required', 'integer', 'exists:enrollments,id'], 'student_id' => ['prohibited']]);
        $group = Group::with('teacherSubject')->findOrFail($id);
        $this->authorize('manage', $group);
        $teacherId = $group->teacherSubject->teacher_id;

        return DB::transaction(function () use ($request, $id, $data, $teacherId) {
            Teacher::whereKey($teacherId)->lockForUpdate()->firstOrFail();
            $group = Group::lockForUpdate()->findOrFail($id);
            $e = app(LegacyTeaching::class)->enrollment($group, $data['enrollment_id']);
            $request->request->remove('enrollment_id');
            if ($request->isJson()) {
                $request->json()->remove('enrollment_id');
            }
            $request->merge(['student_id' => $e->student_id]);
            $response = app(GroupStudentController::class)->addToTeacherGroup($request, $id);

            return $this->legacy($response, $e);
        }, 3);
    }

    public function destroy(Request $request, int $id, int $enrollment)
    {
        $group = Group::findOrFail($id);
        $this->authorize('manage', $group);
        $e = Enrollment::findOrFail($enrollment);
        $this->authorize('manage', $e);
        abort_unless($e->teacher_id === $group->teacherSubject->teacher_id && $e->enrollable_type === 'teacher_subject' && (int) $e->enrollable_id === (int) $group->teacher_subject_id, 404);

        return $this->legacy(app(GroupStudentController::class)->removeFromTeacherGroup($request, $id, $e->student_id), $e);
    }

    private function legacy($response, Enrollment $enrollment)
    {
        $body = $response->getData(true);
        if (isset($body['data']['student_id'])) {
            $body['data']['enrollment_id'] = $enrollment->id;
            $body['data']['enrollment'] = $enrollment->toArray();
            foreach (DB::table('legacy_teaching_links')->where('kind', 'membership')->get() as $link) {
                $old = json_decode($link->payload, true);
                if ((int) $old['group_id'] === (int) $body['data']['group_id'] && (int) $old['enrollment_id'] === $enrollment->id) {
                    $body['data']['id'] = $link->source_id;
                    break;
                }
            }
            $body['data']['group'] = Group::find($body['data']['group_id'])->toArray();
            $response->setData($body);
        }

        return $response;
    }
}
