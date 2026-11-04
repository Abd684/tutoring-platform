<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Models\Individual;
use App\Models\Notification;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Services\IndividualBooking;
use App\Services\ListSearch;
use App\Services\TeachingScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhysicalTeachingController extends ApiController
{
    private function available(Teacher $teacher): void
    {
        abort_unless($teacher->status === 'active' && $teacher->user?->status === 'active', 404);
    }

    public function groups(Request $r, Teacher $teacher)
    {
        $this->available($teacher);
        $p = Group::whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $teacher->id)->where('status', 'active'))->whereIn('status', ['forming', 'active'])->with('teacherSubject.subject')->withCount(['groupStudents as members_count' => fn ($q) => $q->where('status', 'active')])->orderBy('id')->tap(fn ($query) => app(ListSearch::class)->apply($query, $r, 'groups'))->paginate($this->perPage($r));
        $p->setCollection($p->getCollection()->map(function ($g) use ($r) {
            return ['id' => $g->id, 'name' => $g->name, 'teacher_subject_id' => $g->teacher_subject_id, 'content' => app(TeachingScope::class)->payload($g), 'members_count' => $g->members_count, 'max_members' => $g->capacity, 'session_price' => $g->session_price, 'currency' => $g->currency, 'payment_method' => 'cash_to_teacher', 'payment_required_on_platform' => false, 'starts_at' => $g->starts_at, 'ends_at' => $g->ends_at, 'membership_status' => $g->groupStudents()->where('student_id', $r->user()->student->id)->value('status') ?? 'not_joined', 'requires_teacher_approval' => true, 'join_request' => GroupJoinRequest::where('group_id', $g->id)->where('student_id', $r->user()->student->id)->latest('id')->first(), 'members' => $this->memberNames($g), 'sessions' => $g->sessions()->orderBy('starts_at')->get(['id', 'starts_at', 'ends_at', 'status', 'progress_notes', 'progress_updated_at'])];
        }));

        return $this->paginated($p);
    }

    private function memberNames(Group $g)
    {
        return $g->groupStudents()->where('status', 'active')->with('student.user:id,name')->get()->map(fn ($m) => ['student_id' => $m->student_id, 'name' => $m->student?->user?->name]);
    }

    public function members(Request $r, Group $group)
    {
        $this->available($group->teacherSubject->teacher);

        return $this->success(['group_id' => $group->id, 'members_count' => $group->groupStudents()->where('status', 'active')->count(), 'members' => $this->memberNames($group)]);
    }

    public function withdraw(Request $r, Group $group)
    {
        return DB::transaction(function () use ($r, $group) {
            Teacher::whereKey($group->teacherSubject->teacher_id)->lockForUpdate()->firstOrFail();
            $g = Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $m = $g->groupStudents()->where('student_id', $r->user()->student->id)->lockForUpdate()->firstOrFail();
            abort_if(($g->starts_at && $g->starts_at->lte(now())) || $g->sessions()->where('starts_at', '<=', now())->where('status', '!=', 'cancelled')->exists(), 409, 'لا يمكن الانسحاب بعد بدء المجموعة. تواصل مع الأستاذ.');
            $m->update(['status' => 'left', 'left_at' => now()]);

            return $this->success(['membership_status' => 'left', 'refund_eligible' => false, 'payment_method' => 'cash_to_teacher']);
        });
    }

    public function book(Request $r)
    {
        $d = $r->validate(['teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'], 'offer_id' => ['prohibited'], 'student_id' => ['prohibited'], 'teacher_id' => ['prohibited'], 'session_price' => ['prohibited'], 'currency' => ['prohibited'], 'teaching_scope_type' => ['prohibited'], 'teaching_scope_id' => ['prohibited'], 'availability_id' => ['required', 'integer', 'exists:teacher_availabilities,id'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at']]);

        return DB::transaction(function () use ($r, $d) {
            $seed = TeacherSubject::findOrFail($d['teacher_subject_id']);
            $teacher = Teacher::whereKey($seed->teacher_id)->lockForUpdate()->firstOrFail();
            $this->available($teacher);
            $course = TeacherSubject::whereKey($seed->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $course->teacher_id === (int) $teacher->id && $course->status === 'active' && $course->subject?->status === 'active', 404);
            if ($course->individual_session_price === null) {
                return response()->json(['status' => false, 'code' => 'INDIVIDUAL_PRICE_REQUIRED', 'message' => 'لم يحدد الأستاذ سعر الجلسة الفردية لهذه المادة.'], 409);
            }
            $a = TeacherAvailability::where('teacher_id', $teacher->id)->findOrFail($d['availability_id']);
            $start = Carbon::parse($d['starts_at'])->setTimezone($a->timezone);
            $end = Carbon::parse($d['ends_at'])->setTimezone($a->timezone);
            abort_unless($start->equalTo(Carbon::parse($start->toDateString().' '.$a->start_time, $a->timezone)) && $end->equalTo(Carbon::parse($start->toDateString().' '.$a->end_time, $a->timezone)), 422, 'اختر الموعد الكامل كما عرضه الأستاذ.');
            $individual = Individual::create(['student_id' => $r->user()->student->id, 'teacher_subject_id' => $course->id, 'teaching_scope_type' => 'subject', 'teaching_scope_id' => $course->id, 'session_price' => $course->individual_session_price, 'currency' => $course->individual_currency, 'status' => 'active']);
            $session = app(IndividualBooking::class)->book($r->user()->student, $teacher, ['individual_id' => $individual->id] + $d);
            Notification::create(['user_id' => $teacher->user_id, 'type' => 'individual_booked', 'title' => 'حجز موعد فردي', 'body' => 'تم حجز موعد فردي جديد. الدفع عند الأستاذ.', 'data' => ['session_id' => $session->id, 'student_id' => $individual->student_id]]);

            return $this->success(['session' => $session, 'individual' => $individual, 'payment_method' => 'cash_to_teacher', 'payment_required_on_platform' => false], 'تم حجز الموعد.', 201);
        }, 3);
    }
}
