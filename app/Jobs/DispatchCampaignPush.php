<?php

namespace App\Jobs;

use App\Models\NotificationCampaign;
use App\Models\PushRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchCampaignPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $campaignId, public int $afterId = 0) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $campaign = NotificationCampaign::visible()->find($this->campaignId);
        if (! config('notifications.push_enabled') || ! $campaign) {
            return;
        }
        $registrations = PushRegistration::where('is_active', true)->where('id', '>', $this->afterId)
            ->whereHas('user', function ($q) use ($campaign) {
                $q->where('status', 'active')->whereIn('role', ['student', 'teacher']);
                if (in_array($campaign->target_scope, ['students', 'teachers'], true)) {
                    $q->where('role', $campaign->target_scope === 'students' ? 'student' : 'teacher');
                } elseif (in_array($campaign->target_scope, ['student', 'teacher'], true)) {
                    $q->where('role', $campaign->target_scope)->where('id', $campaign->target_user_id);
                }
            })->orderBy('id')->limit(200)->get(['id', 'token_hash']);
        foreach ($registrations as $registration) {
            DeliverNotificationPush::dispatch('campaign', $campaign->id, $registration->id, $registration->token_hash)
                ->onConnection(config('notifications.connection'))->onQueue(config('notifications.queue'))->afterCommit();
        }
        if ($registrations->count() === 200) {
            self::dispatch($campaign->id, $registrations->last()->id)->onConnection(config('notifications.connection'))
                ->onQueue(config('notifications.queue'))->afterCommit();
        }
    }
}
