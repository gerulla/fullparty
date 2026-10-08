<?php

namespace App\Console\Commands;

use App\Services\FFLogs\FFLogsClient;
use App\Services\Notifications\AdminReportService;
use App\Support\Notifications\AdminReportDiagnostics;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
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
                $this->error(__('admin_reports.fflogs_check_result', ['details' => 'HTTP '.$response->status()]));
                // Authentication, rate limiting, and server errors are reported by the client.
                if (! in_array($response->status(), [401, 429], true) && ! $response->serverError()) {
                    $reports->report('fflogs.health', 'admin_reports.fflogs_health_title', 'admin_reports.fflogs_health_message', details: AdminReportDiagnostics::response($response));
                }

                return self::FAILURE;
            }

            $limit = $response->json('data.rateLimitData.limitPerHour');
            $spent = $response->json('data.rateLimitData.pointsSpentThisHour');
            $reset = $response->json('data.rateLimitData.pointsResetIn');
            if (! is_numeric($limit) || (float) $limit <= 0 || ! is_numeric($spent) || ! is_numeric($reset) || $response->json('errors')) {
                $details = [
                    'reason' => AdminReportDiagnostics::reason($response->json('errors') ? 'graphql_errors' : 'invalid_budget'),
                    ...AdminReportDiagnostics::response($response),
                ];
                foreach (['limit' => $limit, 'spent' => $spent, 'reset_seconds' => $reset] as $key => $value) {
                    if (is_numeric($value)) {
                        $details[$key] = 0 + $value;
                    }
                }
                $errors = $response->json('errors');
                if (is_array($errors)) {
                    $details['error_count'] = count($errors);
                }
                $this->error(__('admin_reports.fflogs_check_result', ['details' => $details['reason']]));
                $reports->report('fflogs.health', 'admin_reports.fflogs_health_title', 'admin_reports.fflogs_health_message', details: [
                    ...$details, 'admin_url' => AdminReportDiagnostics::adminUrl('admin.fflogs-playground.index'),
                ]);

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
        } catch (Throwable $exception) {
            $details = AdminReportDiagnostics::exception($exception);
            $this->error(__('admin_reports.fflogs_check_result', ['details' => $details['reason'].' ('.$details['exception_type'].')']));
            // The client reports HTTP/auth/connection failures; cover unexpected local failures here too.
            if (! $exception instanceof ConnectionException && ! $exception instanceof RequestException
                && ! Cache::has('fflogs:client_credentials_token:failed')) {
                $reports->report('fflogs.health', 'admin_reports.fflogs_health_title', 'admin_reports.fflogs_health_message', details: $details);
            }

            return self::FAILURE;
        }
    }
}
