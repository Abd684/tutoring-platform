<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TeacherSubscription;
use App\Services\TeacherDashboard;
use Illuminate\Http\Request;

class TeacherDashboardController extends ApiController
{
    public function current(Request $request, TeacherDashboard $dashboard)
    {
        $this->authorize('viewOwnAny', TeacherSubscription::class);
        $subscription = $dashboard->current($request->user()->teacher);

        return response()->json(['status' => true, 'data' => $subscription ? $dashboard->subscription($subscription) : null]);
    }

    public function history(Request $request, TeacherDashboard $dashboard)
    {
        $this->authorize('viewOwnAny', TeacherSubscription::class);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = $request->user()->teacher->subscriptions()->with('plan')->orderByDesc('starts_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);
        $page->setCollection($page->getCollection()->map(fn ($s) => $dashboard->subscription($s)));

        return $this->paginated($page);
    }

    public function usage(Request $request, TeacherDashboard $dashboard)
    {
        $this->authorize('viewOwnAny', TeacherSubscription::class);

        return $this->success($dashboard->usage($request->user()->teacher));
    }
}
