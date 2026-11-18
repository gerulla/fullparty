<?php

use App\Jobs\CheckIntegrationClientHealthJob;
use App\Models\PendingSocialLink;
use App\Services\Runs\RunSearchActivityRanking;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('runs:refresh-activity-popularity', function (RunSearchActivityRanking $ranking) {
    $ranking->refreshPopularity();
    $this->info('Updated run search activity popularity.');
})->purpose('Refresh activity popularity for the run search picker');

Schedule::command('runs:refresh-activity-popularity')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('notifications:dispatch-run-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('discord:dispatch-pending-welcomes')->everyMinute()->withoutOverlapping();
Schedule::command('resources:cleanup-images')->hourly()->withoutOverlapping();
Schedule::command('images:prune-variants')->hourly()->withoutOverlapping();
Schedule::job(new CheckIntegrationClientHealthJob)->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('fflogs:check-health')->everyFiveMinutes()->withoutOverlapping()->onOneServer()
    ->when(fn () => (bool) config('services.ff_logs.healthcheck_enabled'));
Schedule::command('model:prune', ['--model' => [PendingSocialLink::class]])->hourly()->withoutOverlapping();
