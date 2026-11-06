<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Session;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class SessionNotifications
{
    public function send(Session $session, string $type): void
    {
        $users = Student::whereIn('id', $session->participantIds())->pluck('user_id')->all();
        if ($session->teacher?->user_id) {
            $users[] = $session->teacher->user_id;
        }
        foreach (array_unique($users) as $userId) {
            $key = $type.':'.$session->id.':'.$session->starts_at->getTimestamp();
            if (! DB::table('session_notification_events')->insertOrIgnore([
                'event_key' => $key, 'user_id' => $userId, 'created_at' => now(),
            ])) {
                continue;
            }
            Notification::create(['user_id' => $userId, 'type' => $type,
                'title' => $type === 'session_cancelled' ? 'تم إلغاء الجلسة' : 'تذكير بموعد الجلسة',
                'body' => $type === 'session_cancelled' ? 'تم إلغاء جلستك المقررة في '.$session->starts_at->toIso8601String() : 'تبدأ جلستك خلال ساعة، في '.$session->starts_at->toIso8601String(),
                'data' => ['session_id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String(), 'session_type' => $session->type]]);
        }
    }

    public function remindDue(): int
    {
        $count = 0;
        Session::where('status', 'scheduled')->where('starts_at', '>', now())->where('starts_at', '<=', now()->addHour())
            ->select('id')->chunkById(100, function ($sessions) use (&$count) {
                foreach ($sessions as $seed) {
                    DB::transaction(function () use ($seed, &$count) {
                        $session = Session::lockForUpdate()->find($seed->id);
                        if ($session && $session->status === 'scheduled' && $session->starts_at->isFuture() && $session->starts_at->lte(now()->addHour())) {
                            $this->send($session, 'session_reminder');
                            $count++;
                        }
                    });
                }
            });

        return $count;
    }
}
