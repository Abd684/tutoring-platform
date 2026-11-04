<?php

namespace App\Providers;

use App\Contracts\PdfRenderer;
use App\Services\PopplerPdfRenderer;
use App\Services\ImagickPdfRenderer;

use App\Models\Content;
use App\Models\ContentAsset;
use App\Models\Group;
use App\Models\Individual;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\PaidBooking;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Observers\ContentAssetObserver;
use App\Observers\NotificationObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PdfRenderer::class, fn () => config('pdf.renderer') === 'imagick'
            ? new ImagickPdfRenderer()
            : new PopplerPdfRenderer());
    }

    public function boot(): void
    {
        ContentAsset::observe(ContentAssetObserver::class);
        Notification::observe(NotificationObserver::class);

        Relation::morphMap([
            'teacher_subject' => TeacherSubject::class,
            'unit' => Unit::class,
            'lesson' => Lesson::class,
            'content' => Content::class,
            'paid_booking' => PaidBooking::class,
            'group' => Group::class,
            'individual' => Individual::class,
        ]);

        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(120)->by((string) $key);
        });
    }
}
