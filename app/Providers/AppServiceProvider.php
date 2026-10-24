<?php

namespace App\Providers;

use App\Models\Content;
use App\Models\PaidBooking;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::morphMap([
            'teacher_subject' => TeacherSubject::class,
            'unit' => Unit::class,
            'content' => Content::class,
            'paid_booking' => PaidBooking::class,
        ]);

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(120)->by((string) $key);
        });
    }
}
