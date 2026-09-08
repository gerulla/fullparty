<?php

use App\Jobs\CheckIntegrationClientHealthJob;
use App\Models\PendingSocialLink;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:dispatch-run-reminders')->everyMinute()->withoutOverlapping();
Schedule::job(new CheckIntegrationClientHealthJob)->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [PendingSocialLink::class]])->hourly()->withoutOverlapping();
