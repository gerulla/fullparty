<?php

namespace App\Services\Notifications;

use App\Jobs\SendDiscordAdminReportJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AdminReportService
{
    /**
     * Use a stable key for the incident, not its timestamp or raw exception message.
     * Only pass safe diagnostic parameters: never credentials or request bodies.
     *
     * @param  array<string, string|int|float>  $params
     */
    public function report(string $key, string $titleKey, string $messageKey, array $params = [], string $severity = 'error', bool $immediate = false): bool
    {
        if (! config('services.admin_reports.enabled')) {
            return false;
        }

        $cacheKey = 'admin-reports:'.hash('sha256', $key);
        $claimed = false;

        try {
            $claimed = Cache::add($cacheKey, true, now()->addMinutes(15));

            if (! $claimed) {
                return false;
            }

            $locale = (string) config('app.locale', 'en');
            $job = new SendDiscordAdminReportJob(
                title: Str::limit(__($titleKey, $params, $locale), 240),
                message: Str::limit(__($messageKey, $params, $locale), 1800),
                severity: in_array($severity, ['info', 'warning', 'error'], true) ? $severity : 'error',
            );

            if ($immediate) {
                $result = Bus::dispatchNow($job);

                return $result['sent'] > 0 && $result['failed'] === 0;
            }

            Bus::dispatch($job->afterCommit());

            return true;
        } catch (Throwable $exception) {
            // Alerting must never break the workflow that discovered the problem.
            if ($claimed) {
                rescue(fn () => Cache::forget($cacheKey), report: false);
            }
            Log::warning('Unable to queue an admin report.', ['report_key' => $key, 'exception_type' => $exception::class]);

            return false;
        }
    }
}
