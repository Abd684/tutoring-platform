<?php

namespace App\Http\Controllers\Admin;

use App\Models\Governortate;
use App\Models\Region;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeacherSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends AdminController
{
    public function subjects(Request $request)
    {
        $data = $this->filters($request);
        $query = Subject::withCount('teacherSubjects');
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('code', 'like', '%'.$data['search'].'%'));
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $items = $query->orderBy('name')->paginate(15)->withQueryString();
        $request->validate(['edit' => ['nullable', 'integer', 'exists:subjects,id']]);
        $editing = $request->filled('edit') ? Subject::findOrFail($request->integer('edit')) : new Subject(['status' => 'active']);
        $stats = ['total' => Subject::count(), 'active' => Subject::where('status', 'active')->count(), 'offers' => TeacherSubject::count()];

        return view('admin.catalog.subjects', compact('items', 'editing', 'stats'));
    }

    public function saveSubject(Request $request, ?Subject $subject = null)
    {
        $subject ??= new Subject;
        if (is_string($request->input('code'))) {
            $request->merge(['code' => trim($request->input('code')) === '' ? null : strtoupper(trim($request->input('code')))]);
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('subjects', 'name')->ignore($subject->id)],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('subjects', 'code')->ignore($subject->id)],
            'description' => ['nullable', 'string', 'max:10000'], 'status' => ['required', 'in:active,inactive']]);
        DB::transaction(function () use ($subject, $data) {
            $subject->fill($data)->save();
            $this->audit('subject.saved', $subject);
        });

        return redirect()->route('admin.subjects.index')->with('success', 'تم حفظ المادة مع الاحتفاظ بروابطها وسجلاتها.');
    }

    public function geography(Request $request)
    {
        $request->validate(['kind' => ['nullable', 'in:governortate,region,school'], 'governortate_id' => ['nullable', 'integer', 'exists:governortates,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'], 'edit' => ['nullable', 'integer', 'min:1']]);
        $this->filters($request);
        $kind = $request->input('kind', 'school');
        if ($request->filled('region_id') && $request->filled('governortate_id')) {
            abort_unless(Region::whereKey($request->integer('region_id'))->where('governortate_id', $request->integer('governortate_id'))->exists(), 422);
        }
        $class = $this->geographicClass($kind);
        $query = $class::query();
        if ($kind === 'school') {
            $query->with('region.governortate')->withCount('students');
            if ($request->filled('region_id')) {
                $query->where('region_id', $request->integer('region_id'));
            }if ($request->filled('governortate_id')) {
                $query->whereHas('region', fn ($q) => $q->where('governortate_id', $request->integer('governortate_id')));
            }
        }
        if ($kind === 'region') {
            $query->with('governortate')->withCount('schools');
            if ($request->filled('governortate_id')) {
                $query->where('governortate_id', $request->integer('governortate_id'));
            }
        }
        if ($kind === 'governortate') {
            $query->withCount('regions');
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }
        $items = $query->orderBy('name')->paginate(15)->withQueryString();
        $editing = $request->filled('edit') ? $class::findOrFail($request->integer('edit')) : new $class;
        $governortates = Governortate::orderBy('name')->get();
        $regions = Region::with('governortate')->orderBy('name')->get();
        $stats = ['governortates' => Governortate::count(), 'regions' => Region::count(), 'schools' => School::count()];

        return view('admin.catalog.geography', compact('kind', 'items', 'editing', 'governortates', 'regions', 'stats'));
    }

    private function geographicClass(string $kind): string
    {
        return match ($kind) {
            'governortate' => Governortate::class,'region' => Region::class,'school' => School::class,default => abort(404)
        };
    }

    public function createGeography(Request $request)
    {
        $request->validate(['kind' => ['required', 'in:governortate,region,school']]);

        return $this->saveGeography($request, $request->input('kind'));
    }

    public function updateGeography(Request $request, string $kind, int $item)
    {
        return $this->saveGeography($request, $kind, $item);
    }

    private function saveGeography(Request $request, string $kind, ?int $item = null)
    {
        $class = $this->geographicClass($kind);
        $record = $item ? $class::findOrFail($item) : new $class;
        $table = $record->getTable();
        $unique = Rule::unique($table, 'name')->ignore($record->id);
        if ($kind === 'region') {
            $unique->where('governortate_id', $request->input('governortate_id'));
        }
        if ($kind === 'school') {
            $unique->where('region_id', $request->input('region_id'));
        }
        $rules = ['name' => ['required', 'string', 'max:255', $unique]];
        if ($kind === 'region') {
            $rules['governortate_id'] = ['required', 'integer', 'exists:governortates,id'];
        }
        if ($kind === 'school') {
            if (is_string($request->input('code'))) {
                $request->merge(['code' => trim($request->input('code')) === '' ? null : strtoupper(trim($request->input('code')))]);
            }
            $rules += ['region_id' => ['required', 'integer', 'exists:regions,id'], 'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('schools', 'code')->ignore($record->id)], 'education_stage' => ['nullable', 'in:basic,secondary,vocational,other']];
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($record, $data, $kind) {
            if ($record->exists) {
                $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            }
            if ($kind === 'region' && $record->exists && (int) $record->governortate_id !== (int) $data['governortate_id'] && ($record->schools()->exists() || $record->users()->exists())) {
                throw ValidationException::withMessages(['governortate_id' => 'لا يمكن نقل منطقة مرتبطة بحسابات أو مدارس إلى محافظة أخرى.']);
            }
            if ($kind === 'school' && $record->exists && (int) $record->region_id !== (int) $data['region_id'] && $record->students()->exists()) {
                throw ValidationException::withMessages(['region_id' => 'لا يمكن نقل مدرسة مرتبطة بطلاب إلى منطقة أخرى.']);
            }
            $record->fill($data)->save();
            $this->audit('geography.'.$kind.'.saved', $record);
        });

        return redirect()->route('admin.geography',['kind' => $kind])->with('success','تم حفظ البيانات الجغرافية.');
    }
}
