<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Teacher;
use Illuminate\Http\Request;

class TeacherDirectoryController extends ApiController
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'specialization' => ['sometimes', 'nullable', 'string', 'max:100'],
            'region_id' => ['sometimes', 'integer', 'min:1'], 'subject_id' => ['sometimes', 'integer', 'min:1'],
            'grade' => ['prohibited'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ], ['grade.prohibited' => 'Grade filtering is not supported: teaching grades are not mapped to teacher subjects.']);
        $query = Teacher::where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->with('user:id,name,region_id,avatar_path,status');
        foreach (['search', 'specialization'] as $field) {
            $value = trim($data[$field] ?? '');
            if ($value === '') {
                continue;
            }
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)).'%';
            if ($field === 'search') {
                $query->whereHas('user', fn ($q) => $q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
            } else {
                $query->whereRaw("LOWER(specialization) LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        if (isset($data['region_id'])) {
            $query->whereHas('user', fn ($q) => $q->where('region_id', $data['region_id']));
        }
        if (isset($data['subject_id'])) {
            $query->whereHas('teacherSubjects', fn ($q) => $q->where('subject_id', $data['subject_id'])->where('status', 'active')->whereHas('subject', fn ($q) => $q->where('status', 'active')));
        }
        $page = $query->orderBy('id')->paginate($data['per_page'] ?? 20);
        $page->setCollection($page->getCollection()->map(fn ($teacher) => [
            'id' => $teacher->id, 'name' => $teacher->user->name, 'specialization' => $teacher->specialization,
            'avatar_url' => $teacher->user->avatar_url, 'status' => $teacher->status, 'region_id' => $teacher->user->region_id,
        ]));

        return $this->paginated($page);
    }
}
