<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Session;
use App\Services\ListSearch;
use Illuminate\Http\Request;

class StudentBookingController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorize('book', Session::class);
        $request->validate(['scope' => ['sometimes', 'in:upcoming,history,all']]);
        $scope = $request->input('scope', 'upcoming');
        $sessions = Session::forStudent($request->user()->student->id)->with(['teacher.user:id,name', 'target.teacherSubject.subject'])
            ->when($scope === 'upcoming', fn ($q) => $q->where('starts_at', '>=', now())->where('status', 'scheduled'))
            ->when($scope === 'history', fn ($q) => $q->where(fn ($q) => $q->where('ends_at', '<=', now())->orWhereIn('status', ['completed', 'cancelled'])))
            ->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'sessions'))->orderBy('starts_at')->get();

        return response()->json(['data' => $sessions->map(fn ($session) => $this->payload($session))]);
    }

    public function show(Request $request, Session $session)
    {
        $this->authorize('studentView', $session);

        return $this->success($this->payload($session->load(['teacher.user:id,name', 'target.teacherSubject.subject'])));
    }

    private function payload(Session $session): array
    {
        return $session->only(['id', 'type', 'type_id', 'starts_at', 'ends_at', 'status', 'progress_notes', 'progress_updated_at']) + [
            'teacher' => ['id' => $session->teacher_id, 'name' => $session->teacher?->user?->name],
            'teacher_subject_id' => $session->target?->teacher_subject_id,
            'subject_name' => $session->target?->teacherSubject?->subject?->name];
    }
}
