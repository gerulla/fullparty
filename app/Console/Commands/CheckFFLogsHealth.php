<?php

namespace App\Console\Commands;

use App\Services\FFLogs\FFLogsClient;
use App\Services\Notifications\AdminReportService;
use Illuminate\Console\Command;
use Throwable;

class CheckFFLogsHealth extends Command
{
    protected $signature = 'fflogs:check-health';

    protected $description = 'Check FF Logs authentication and API budget, and report problems to admins.';

    public function handle(FFLogsClient $client, AdminReportService $reports): int
    {
        try {
            $response = $client->query(['query' => '{ rateLimitData { limitPerHour pointsSpentThisHour pointsResetIn } }']);

            if (! $response->successful()) {
                // Authentication, rate limiting, and server errors are reported by the client.
                if (! in_array($response->status(), [401, 429], true) && ! $response->serverError()) {
                    $reports->report('fflogs.health', 'admin_reports.fflogs_health_title', 'admin_reports.fflogs_health_message');
                }

                return self::FAILURE;
            }

            $limit = $response->json('data.rateLimitData.limitPerHour');
            $spent = $response->json('data.rateLimitData.pointsSpentThisHour');
            $reset = $response->json('data.rateLimitData.pointsResetIn');
            if (! is_numeric($limit) || (float) $limit <= 0 || ! is_numeric($spent) || ! is_numeric($reset) || $response->json('errors')) {
                $reports->report('fflogs.health', 'admin_reports.fflogs_health_title', 'admin_reports.fflogs_health_message');

                return self::FAILURE;
            }

            if ((float) $spent >= (float) $limit * 0.9) {
                $reports->report(
                    key: 'fflogs.quota',
                    titleKey: 'admin_reports.fflogs_quota_title',
                    messageKey: 'admin_reports.fflogs_quota_message',
                    params: ['spent' => $spent, 'limit' => $limit, 'seconds' => $reset],
                    severity: 'warning',
                );
            }

            return self::SUCCESS;
        } catch (Throwable) {
            // The client reports authentication/connection failures without leaking secrets.
            return self::FAILURE;
        }
    }
}
