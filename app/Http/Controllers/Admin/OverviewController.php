<?php

namespace App\Http\Controllers\Admin;

use App\Models\Payment;
use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherSubscription;
use Illuminate\Http\Request;

class OverviewController extends AdminController
{
    public function __invoke(Request $request)
    {
        $stats = ['students' => Student::count(), 'teachers' => Teacher::where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))->count(),
            'sessions' => Session::where('status', 'scheduled')->whereBetween('starts_at', [now(), now()->addDay()])->count(),
            'payments' => Payment::where('status', 'pending')->count()];
        $payments = Payment::with('payer')->where('status', 'pending')->orderByDesc('id')->limit(5)->get();
        $sessions = Session::with(['teacher.user', 'group.teacherSubject.subject'])->whereIn('status', ['scheduled', 'ongoing'])->where('ends_at', '>', now())->orderBy('starts_at')->limit(4)->get();
        $renewals = TeacherSubscription::with(['teacher.user', 'plan'])->where('status', 'active')->whereBetween('ends_at', [now(), now()->addDays(7)])->orderBy('ends_at')->limit(6)->get();
        if ($request->boolean('export')) {
            return $this->csv(TeacherSubscription::with(['teacher.user', 'plan'])->orderBy('id'), ['المعرف' => 'id', 'المدرس' => 'teacher.user.name', 'الخطة' => 'plan.name', 'الحالة' => 'status', 'النهاية' => 'ends_at'], 'subscriptions');
        }

        return view('admin.overview', compact('stats', 'payments', 'sessions', 'renewals'));
    }
}
