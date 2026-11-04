<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Teacher;
use App\Services\ListSearch;
use Illuminate\Http\Request;

class TeacherDirectoryController extends ApiController
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'in:online,physical'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:100'],
            'region_id' => ['sometimes', 'integer', 'min:1'], 'subject_id' => ['sometimes', 'integer', 'min:1'],
            'grade' => ['prohibited'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ], ['grade.prohibited' => 'Grade filtering is not supported: teaching grades are not mapped to teacher subjects.']);
        $query = Teacher::where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->with('user:id,name,region_id,avatar_path,status');
        $specialization = trim($data['specialization'] ?? '');
        if ($specialization !== '') {
            $query->whereRaw("LOWER(specialization) LIKE ? ESCAPE '!'", [app(ListSearch::class)->pattern($specialization)]);
        }
        if (isset($data['region_id'])) {
            $query->whereHas('user', fn ($q) => $q->where('region_id', $data['region_id']));
        }
        if (isset($data['subject_id'])) {
            $query->whereHas('teacherSubjects', fn ($q) => $q->where('subject_id', $data['subject_id'])->where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active')));
        }
        $page = $query->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'teachers'))->orderBy('id')->paginate($data['per_page'] ?? 20);
        $page->setCollection($page->getCollection()->map(fn ($teacher) => [
            'id' => $teacher->id, 'name' => $teacher->user->name, 'specialization' => $teacher->specialization,
            'avatar_url' => $teacher->user->avatar_url, 'modes' => ['online', 'physical'], 'status' => $teacher->status, 'region_id' => $teacher->user->region_id,
        ]));

        return $this->paginated($page);
    }
}
