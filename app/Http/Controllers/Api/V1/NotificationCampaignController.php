<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\NotificationCampaign;
use App\Services\ListSearch;
use App\Services\NotificationCampaigns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotificationCampaignController extends ApiController
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', NotificationCampaign::class);
        $data = $request->validate(['status' => ['sometimes', 'in:draft,scheduled,dispatched,cancelled']]);

        return $this->paginated(NotificationCampaign::when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('id')->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'campaigns'))->paginate($this->perPage($request)));
    }

    public function store(Request $request)
    {
        $this->authorize('viewAny', NotificationCampaign::class);
        $data = $this->fields($request);

        return DB::transaction(function () use ($data, $request) {
            $campaign = NotificationCampaign::create($data + ['created_by' => $request->user()->id,
                'status' => empty($data['scheduled_at']) ? 'draft' : 'scheduled']);
            $this->audit($campaign, 'created');

            return $this->success($campaign, 'Campaign saved; dispatch is separate.', 201);
        });
    }

    public function show(NotificationCampaign $campaign)
    {
        $this->authorize('manage', $campaign);

        return $this->success($campaign);
    }

    public function update(Request $request, NotificationCampaign $campaign)
    {
        $this->authorize('manage', $campaign);
        $data = $this->fields($request);

        return DB::transaction(function () use ($campaign, $data) {
            $campaign = NotificationCampaign::lockForUpdate()->findOrFail($campaign->id);
            abort_unless(in_array($campaign->status, ['draft', 'scheduled'], true), 409, 'A dispatched or cancelled campaign is immutable.');
            $campaign->update($data + ['status' => empty($data['scheduled_at']) ? 'draft' : 'scheduled']);
            $this->audit($campaign, 'updated');

            return $this->success($campaign);
        }, 3);
    }

    public function dispatch(NotificationCampaign $campaign, NotificationCampaigns $service)
    {
        $this->authorize('manage', $campaign);

        return $this->success($service->dispatch($campaign, false, auth()->id()), 'Campaign published in the inbox; push is queued when enabled.');
    }

    public function cancel(NotificationCampaign $campaign)
    {
        $this->authorize('manage', $campaign);

        return DB::transaction(function () use ($campaign) {
            $campaign = NotificationCampaign::lockForUpdate()->findOrFail($campaign->id);
            abort_if($campaign->status === 'dispatched', 409, 'A delivered campaign cannot be unsent.');
            if ($campaign->status === 'cancelled') {
                return $this->success($campaign);
            }
            $campaign->update(['status' => 'cancelled']);
            $this->audit($campaign, 'cancelled');

            return $this->success($campaign);
        }, 3);
    }

    private function fields(Request $request): array
    {
        $singular = in_array($request->input('target_scope'), ['student', 'teacher'], true);

        return $request->validate([
            'title' => ['required', 'string', 'max:180'], 'body' => ['required', 'string', 'max:2000'],
            'target_scope' => ['required', 'in:all,students,teachers,student,teacher'],
            'target_user_id' => [Rule::requiredIf($singular), Rule::prohibitedIf(! $singular), 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', $request->input('target_scope'))->where('status', 'active'))],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'data' => ['nullable', 'array', 'max:20'], 'data.*' => ['nullable', 'string', 'max:512'],
            'status' => ['prohibited'], 'dispatched_at' => ['prohibited'], 'created_by' => ['prohibited'],
        ]);
    }

    private function audit(NotificationCampaign $campaign, string $action): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => 'notification_campaign.'.$action,
            'entity_type' => NotificationCampaign::class, 'entity_id' => $campaign->id,
            'metadata_json' => ['target_scope' => $campaign->target_scope, 'status' => $campaign->status], 'created_at' => now()]);
    }
}
