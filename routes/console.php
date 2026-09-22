<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('monitors:evaluate')->everyMinute();
Schedule::command('digests:publish-weekly')->everyMinute();
Schedule::command('digests:generate-weekly')->weeklyOn(1, '00:05')->timezone('UTC');
