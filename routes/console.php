<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:prune-data-api-cache --hours=168')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('app:finalize-stale-call-media --grace-seconds=30')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('app:submit-latest-sitrep-to-relay')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
