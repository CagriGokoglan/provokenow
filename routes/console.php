<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('provoke:sitemap')->hourly()->withoutOverlapping();
Schedule::command('gifts:deliver')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Queue worker without a process manager: cron runs schedule:run every minute, and this drains
// the queue (order emails, Meta events, image optimisation) and exits before the next minute.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(5)->runInBackground();
