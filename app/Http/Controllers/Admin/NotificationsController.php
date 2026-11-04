<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\NotificationCampaignController;
use App\Models\NotificationCampaign;
use App\Services\NotificationCampaigns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationsController extends AdminController
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', NotificationCampaign::class);
        $data = $request->validate(['status'=>['nullable','in:draft,scheduled,dispatched,cancelled'], 'edit'=>['nullable','integer','exists:notification_campaigns,id']]);
        $items = NotificationCampaign::when($data['status'] ?? null, fn($q,$status)=>$q->where('status',$status))->latest('id')->paginate(15)->withQueryString();
        $editing = $request->filled('edit') ? NotificationCampaign::findOrFail($data['edit']) : new NotificationCampaign(['target_scope'=>'all','status'=>'draft']);
        $recipientIds = $items->pluck('target_user_id')->push($editing->target_user_id)->push(old('target_user_id'))->filter();
        $recipients = \App\Models\User::whereIn('id', $recipientIds)->get(['id','name','phone'])->keyBy('id');
        return view('admin.notifications.index', compact('items','editing','recipients'));
    }

    public function recipients(Request $request)
    {
        Gate::authorize('viewAny', NotificationCampaign::class);
        $data=$request->validate(['scope'=>['required','in:student,teacher'],'q'=>['nullable','string','max:100']]);
        $query=\App\Models\User::where('status','active')->where('role',$data['scope']);
        if (!empty($data['q'])) {
            $term=addcslashes($data['q'], '%_\\');
            $query->where('name','like','%'.$term.'%');
        }
        return response()->json(['data'=>$query->orderBy('name')->orderBy('id')->limit(30)->get(['id','name','phone'])]);
    }

    public function store(Request $request, NotificationCampaignController $api)
    {
        $this->normalizeDate($request);
        $response = $api->store($request);
        return redirect()->route('admin.notifications.index', ['edit'=>$response->getData(true)['data']['id']])->with('success','تم حفظ الحملة. المسودة لا تُرسل حتى تضغط نشر الآن، والمجدولة تُنشر في موعدها.');
    }

    public function update(Request $request, NotificationCampaign $campaign, NotificationCampaignController $api)
    {
        $this->normalizeDate($request);
        $api->update($request,$campaign);
        return redirect()->route('admin.notifications.index',['edit'=>$campaign->id])->with('success','تم حفظ التعديلات.');
    }

    public function dispatch(NotificationCampaign $campaign, NotificationCampaignController $api, NotificationCampaigns $service)
    {
        return $this->apiResult($api->dispatch($campaign,$service),'تم نشر الحملة في صندوق الإشعارات وإضافة إرسال الأجهزة إلى قائمة الانتظار عند تفعيله.');
    }

    private function normalizeDate(Request $request): void
    {
        if ($request->filled('scheduled_at')) {
            $request->validate(['scheduled_at'=>['date_format:Y-m-d\\TH:i']]);
            $request->merge(['scheduled_at'=>\Carbon\Carbon::createFromFormat('Y-m-d\\TH:i', $request->input('scheduled_at'), \App\Models\PlatformSetting::dashboard()['timezone'])->toIso8601String()]);
        }
    }

    public function cancel(NotificationCampaign $campaign, NotificationCampaignController $api)
    {
        return $this->apiResult($api->cancel($campaign),'تم إلغاء الحملة.');
    }
}
