<?php

namespace App\Observers;

use App\Jobs\DeliverNotificationPush;
use App\Models\Notification;
use App\Models\PushRegistration;

class NotificationObserver
{
    public function created(Notification $notification): void
    {
        if (! config('notifications.push_enabled')) {
            return;
        }
        PushRegistration::where('user_id', $notification->user_id)->where('is_active', true)
            ->select(['id', 'token_hash'])->chunkById(100, function ($registrations) use ($notification) {
                foreach ($registrations as $registration) {
                    DeliverNotificationPush::dispatch('personal', $notification->id, $registration->id, $registration->token_hash)
                        ->onConnection(config('notifications.connection'))->onQueue(config('notifications.queue'))->afterCommit();
                }
            });
    }
}
