<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Individual;
use App\Models\IndividualOffer;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Services\TeachingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IndividualOfferController extends ApiController
{
    public function index(Request $r)
    {
        return $this->paginated(IndividualOffer::whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $r->user()->teacher->id))->orderByDesc('id')->paginate($this->perPage($r)));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'], 'teaching_scope_type' => ['required', 'in:subject,unit,lesson'], 'teaching_scope_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:255'], 'session_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99']]);
        $c = TeacherSubject::findOrFail($d['teacher_subject_id']);
        $this->authorize('manage', $c);
        app(TeachingScope::class)->resolve($c, $d['teaching_scope_type'], $d['teaching_scope_id']);

        return $this->success(IndividualOffer::create($d + ['currency' => 'SYP', 'status' => 'active']), 'تم إنشاء العرض.', 201);
    }

    public function update(Request $r, IndividualOffer $individualOffer)
    {
        $this->authorize('manage', $individualOffer->teacherSubject);
        $d = $r->validate(['title' => ['sometimes', 'string', 'max:255'], 'status' => ['sometimes', 'in:active,inactive'], 'session_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'], 'teacher_subject_id' => ['prohibited'], 'teaching_scope_type' => ['prohibited'], 'teaching_scope_id' => ['prohibited']]);

        return DB::transaction(function () use ($individualOffer, $d) {
            Teacher::whereKey($individualOffer->teacherSubject->teacher_id)->lockForUpdate()->firstOrFail();
            $offer = IndividualOffer::whereKey($individualOffer->id)->lockForUpdate()->firstOrFail();
            abort_if(isset($d['session_price']) && Individual::where('individual_offer_id', $offer->id)->exists(), 409, 'سعر الحصة ثابت بعد بدء الحجز. أنشئ عرضاً جديداً عند الحاجة.');
            $offer->update($d);

            return $this->success($offer);
        });
    }
}
