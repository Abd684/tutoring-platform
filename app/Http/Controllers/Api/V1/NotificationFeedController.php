<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NotificationCampaign;
use App\Services\ListSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationFeedController extends ApiController
{
    public function index(Request $request)
    {
        $data = $request->validate(['unread_only' => ['sometimes', 'boolean'], 'kind' => ['sometimes', 'in:all,personal,campaign']]);
        $user = $request->user();
        $personal = DB::table('notifications')->where('user_id', $user->id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('read_at')->where('created_at', '>', now()->subDays(30)))
                ->orWhere(fn ($q) => $q->whereNotNull('read_at')->where('read_at', '>', now()->subDays(7))))
            ->selectRaw("'personal' AS kind, id, type, title, body, data, read_at, created_at, related_payment_id");
        $campaigns = NotificationCampaign::visible()->forRecipient($user)
            ->leftJoin('notification_campaign_reads as r', function ($join) use ($user) {
                $join->on('r.campaign_id', '=', 'notification_campaigns.id')->where('r.user_id', '=', $user->id);
            })->selectRaw("'campaign' AS kind, notification_campaigns.id, 'campaign' AS type, title, body, data, r.read_at, dispatched_at AS created_at, NULL AS related_payment_id");
        $kind = $data['kind'] ?? 'all';
        $union = $kind === 'personal' ? $personal : ($kind === 'campaign' ? $campaigns->toBase() : $personal->unionAll($campaigns->toBase()));
        $query = DB::query()->fromSub($union, 'feed');
        $unread = (clone $query)->whereNull('read_at')->count();
        app(ListSearch::class)->apply($query, $request, 'feed');
        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }
        $page = $query->orderByDesc('created_at')->orderBy('kind')->orderByDesc('id')->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map(function ($row) {
            $item = (array) $row;
            $item['id'] = (int) $item['id'];
            $item['key'] = $item['kind'].':'.$item['id'];
            $item['read'] = $item['read_at'] !== null;
            foreach (['created_at', 'read_at'] as $date) {
                $item[$date] = $item[$date] === null ? null : Carbon::parse($item[$date], config('app.timezone'))->utc()->toISOString();
            }
            $item['data'] = is_string($item['data']) ? json_decode($item['data'], true) : $item['data'];

            return $item;
        }));
        $response = $this->paginated($page);
        $body = $response->getData(true);
        $body['meta']['unread_count'] = $unread;

        return $response->setData($body);
    }

    public function read(Request $request, NotificationCampaign $campaign)
    {
        abort_unless(NotificationCampaign::visible()->forRecipient($request->user())->whereKey($campaign->id)->exists(), 404);
        DB::table('notification_campaign_reads')->insertOrIgnore(['campaign_id' => $campaign->id, 'user_id' => $request->user()->id, 'read_at' => now()]);

        return $this->success(['id' => $campaign->id, 'kind' => 'campaign', 'key' => 'campaign:'.$campaign->id, 'read' => true,
            'read_at' => Carbon::parse(DB::table('notification_campaign_reads')->where('campaign_id', $campaign->id)->where('user_id', $request->user()->id)->value('read_at'), config('app.timezone'))->utc()->toISOString()]);
    }
}
