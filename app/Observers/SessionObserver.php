<?php

namespace App\Observers;

use App\Models\Session;
use App\Services\SessionNotifications;
use Illuminate\Support\Facades\DB;

class SessionObserver
{
    public function updated(Session $session): void
    {
        if ($session->wasChanged('status') && $session->status === 'cancelled') {
            DB::transaction(fn () => app(SessionNotifications::class)->send($session, 'session_cancelled'));
        }
    }
}
