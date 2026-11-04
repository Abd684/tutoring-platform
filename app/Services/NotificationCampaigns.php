<?php

namespace App\Services;

use App\Jobs\DispatchCampaignPush;
use App\Models\AuditLog;
use App\Models\NotificationCampaign;
use Illuminate\Support\Facades\DB;

class NotificationCampaigns
{
    public function dispatch(NotificationCampaign $campaign, bool $dueOnly = false, ?int $actorId = null): NotificationCampaign
    {
        return DB::transaction(function () use ($campaign, $dueOnly, $actorId) {
            $campaign = NotificationCampaign::lockForUpdate()->findOrFail($campaign->id);
            abort_if($dueOnly && ($campaign->status !== 'scheduled' || ! $campaign->scheduled_at || $campaign->scheduled_at->isFuture()), 409, 'Campaign is no longer due.');
            if ($campaign->status === 'dispatched') {
                return $campaign;
            }
            abort_unless(in_array($campaign->status, ['draft', 'scheduled'], true), 409, 'Campaign cannot be dispatched.');
            $campaign->update(['status' => 'dispatched', 'dispatched_at' => now()]);
            AuditLog::create(['user_id' => $actorId, 'action' => 'notification_campaign.dispatched',
                'entity_type' => NotificationCampaign::class, 'entity_id' => $campaign->id,
                'metadata_json' => ['target_scope' => $campaign->target_scope, 'scheduled' => $dueOnly], 'created_at' => now()]);
            if (config('notifications.push_enabled')) {
                DispatchCampaignPush::dispatch($campaign->id)->onConnection(config('notifications.connection'))
                    ->onQueue(config('notifications.queue'))->afterCommit();
            }

            return $campaign;
        }, 3);
    }
}
