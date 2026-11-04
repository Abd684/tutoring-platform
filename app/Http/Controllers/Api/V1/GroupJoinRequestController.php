<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Services\GroupJoinRequests;
use App\Services\ListSearch;
use Illuminate\Http\Request;

class GroupJoinRequestController extends ApiController
{
    public function store(Request $request, Group $group, GroupJoinRequests $service)
    {
        $this->authorize('accessStudent', Group::class);
        $data = $request->validate(['message' => ['sometimes', 'nullable', 'string', 'max:1000'], 'student_id' => ['prohibited'], 'status' => ['prohibited']]);
        [$join, $created] = $service->submit($group, $request->user()->student, $data['message'] ?? null);

        return $this->success(['join_request' => $join, 'requires_teacher_approval' => true, 'seat_reserved' => false, 'payment_method' => 'cash_to_teacher'], 'طلب الانضمام بانتظار الأستاذ.', $created ? 201 : 200);
    }

    public function studentIndex(Request $request, Group $group)
    {
        $this->authorize('accessStudent', Group::class);

        return $this->paginated(GroupJoinRequest::where('group_id', $group->id)->where('student_id', $request->user()->student->id)->orderByDesc('id')->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'join_requests'))->paginate($this->perPage($request)));
    }

    public function teacherIndex(Request $request, Group $group)
    {
        $this->authorize('manage', $group);
        $data = $request->validate(['status' => ['sometimes', 'in:pending,approved,rejected,all']]);
        $query = GroupJoinRequest::where('group_id', $group->id)->with('student.user:id,name,phone,avatar_path');
        if (($data['status'] ?? 'pending') !== 'all') {
            $query->where('status', $data['status'] ?? 'pending');
        }

        return $this->paginated($query->orderByDesc('id')->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'join_requests'))->paginate($this->perPage($request)));
    }

    public function decide(Request $request, Group $group, GroupJoinRequest $joinRequest, string $decision, GroupJoinRequests $service)
    {
        $this->authorize('manage', $group);
        abort_unless($joinRequest->group_id === $group->id, 404);
        $this->authorize('review', $joinRequest);
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000'], 'status' => ['prohibited']]);
        // decision is a route parameter, not a body field.
        if ($decision === 'reject') {
            $request->validate(['review_note' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        }
        $join = $service->decide($group, $joinRequest, $decision, $data['review_note'] ?? null, $request->user()->id);
        $membership = $group->groupStudents()->where('student_id', $join->student_id)->first();

        return $this->success(['join_request' => $join, 'membership' => $membership]);
    }
}
