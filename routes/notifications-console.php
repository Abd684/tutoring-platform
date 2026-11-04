<?php

use App\Models\NotificationCampaign;
use App\Services\NotificationCampaigns;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\HttpKernel\Exception\HttpException;

Artisan::command('notifications:dispatch-due', function () {
    $count = 0;
    NotificationCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())
        ->select('id')->chunkById(100, function ($campaigns) use (&$count) {
            foreach ($campaigns as $campaign) {
                try {
                    app(NotificationCampaigns::class)->dispatch($campaign, true);
                    $count++;
                } catch (HttpException $error) {
                    if ($error->getStatusCode() !== 409) {
                        throw $error;
                    }
                }
            }
        });
    $this->info('Dispatched campaigns: '.$count);
});

Schedule::command('notifications:dispatch-due')->everyMinute()->withoutOverlapping();

// Preview by default. Never schedule a destructive retention job implicitly.
Artisan::command('notifications:prune {--execute} {--dry-run}', function () {
    $personal = fn () => DB::table('notifications')->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('read_at')->where('read_at', '<=', now()->subDays(7)))
        ->orWhere(fn ($q) => $q->whereNull('read_at')->where('created_at', '<=', now()->subDays(30))));
    $campaigns = fn () => DB::table('notification_campaigns')
        ->where('status', 'dispatched')->where('dispatched_at', '<=', now()->subDays(30));
    $counts = ['personal' => $personal()->count(), 'campaigns' => $campaigns()->count()];
    if (! $this->option('execute') || $this->option('dry-run')) {
        $this->info('Dry run: '.json_encode($counts));

        return;
    }
    foreach ([$personal, $campaigns] as $query) {
        do {
            $ids = $query()->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isNotEmpty()) {
                $query()->whereIn('id', $ids)->delete();
            }
        } while ($ids->isNotEmpty());
    }
    $this->info('Expired notification records pruned.');
});
