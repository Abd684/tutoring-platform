<?php

namespace App\Http\Controllers\Admin;

use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\TeacherSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionsController extends AdminController
{
    public function index(Request $request)
    {
        $data = $this->filters($request);
        $query = TeacherSubscription::with(['teacher.user', 'plan']);
        if (! empty($data['search'])) {
            $query->whereHas('teacher.user', fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%'));
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $items = $query->orderByDesc('starts_at')->paginate(15)->withQueryString();
        $plans = SubscriptionPlan::where('status', 'active')->get();
        $teachers = Teacher::with('user')->where('status', 'active')->get();
        $stats = ['total' => TeacherSubscription::count(), 'active' => TeacherSubscription::where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->count(), 'expiring' => TeacherSubscription::where('status', 'active')->whereBetween('ends_at', [now(), now()->addDays(7)])->count()];

        return view('admin.subscriptions.index', compact('items', 'plans', 'teachers', 'stats'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['teacher_id' => ['required', 'integer', 'exists:teachers,id'], 'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')->where('status', 'active')],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'auto_renew' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($data) {
            $teacher = Teacher::whereKey($data['teacher_id'])->lockForUpdate()->firstOrFail();
            if ($teacher->status !== 'active' || $teacher->user?->status !== 'active') {
                throw ValidationException::withMessages(['teacher_id' => ['المدرس غير فعال.']]);
            }
            if (TeacherSubscription::where('teacher_id', $teacher->id)->where('status', 'active')->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists()) {
                throw ValidationException::withMessages(['starts_at' => ['يوجد اشتراك فعال يتداخل مع الفترة. ألغِ الاشتراك السابق أو اختر فترة أخرى.']]);
            }
            $subscription = TeacherSubscription::create([...$data, 'auto_renew' => $data['auto_renew'] ?? false, 'status' => 'active']);
            $this->audit('subscription.assigned', $subscription);
        });

        return back()->with('success', 'تم إسناد الخطة إدارياً، دون تسجيل دفعة مالية.');
    }

    public function cancel(TeacherSubscription $subscription)
    {
        DB::transaction(function () use ($subscription) {
            Teacher::whereKey($subscription->teacher_id)->lockForUpdate()->firstOrFail();
            $subscription = TeacherSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            abort_unless($subscription->status === 'active', 409);
            $subscription->update(['status' => 'cancelled']);
            $this->audit('subscription.cancelled', $subscription);
        });

        return back()->with('success', 'تم إلغاء الاشتراك مع حفظ سجله.');
    }

    public function plans(Request $request)
    {
        $data = $this->filters($request);
        $query = SubscriptionPlan::withCount('teacherSubscriptions');
        if (! empty($data['search'])) {
            $query->where('name', 'like', '%'.$data['search'].'%');
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        $items = $query->orderBy('price')->paginate(15)->withQueryString();

        return view('admin.subscriptions.plans', compact('items'));
    }

    public function createPlan()
    {
        $plan = new SubscriptionPlan(['billing_cycle' => 'monthly', 'status' => 'active', 'price' => 0, 'commission_rate' => 0]);

        return view('admin.subscriptions.plan', compact('plan'));
    }

    public function editPlan(SubscriptionPlan $plan)
    {
        return view('admin.subscriptions.plan', compact('plan'));
    }

    public function storePlan(Request $request)
    {
        return $this->savePlan($request, new SubscriptionPlan);
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan)
    {
        return $this->savePlan($request, $plan);
    }

    private function savePlan(Request $request, SubscriptionPlan $plan)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'price' => ['required', 'numeric', 'between:0,9999999999.99'], 'billing_cycle' => ['required', 'in:monthly,yearly'],
            'commission_rate' => ['required', 'numeric', 'between:0,100'], 'max_students' => ['nullable', 'integer', 'min:0'], 'max_storage' => ['nullable', 'integer', 'min:0'],
            'max_ai_usage' => ['nullable', 'integer', 'min:0'], 'max_group_size' => ['nullable', 'integer', 'min:1'], 'status' => ['required', 'in:active,inactive']]);
        DB::transaction(function () use ($plan, $data) {
            $plan->fill($data)->save();
            $this->audit('plan.saved', $plan);
        });

        return redirect()->route('admin.plans.index')->with('success', 'تم حفظ الخطة.');
    }
}
