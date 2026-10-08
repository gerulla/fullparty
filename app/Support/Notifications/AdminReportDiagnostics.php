<?php

namespace App\Support\Notifications;

use App\Exceptions\InsecureIntegrationEndpointException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

final class AdminReportDiagnostics
{
    /** @return array<string, string|int|float> */
    public static function response(Response $response): array
    {
        $details = ['http_status' => $response->status()];
        $retryAfter = $response->header('Retry-After');
        if (preg_match('/^\d{1,8}$/', $retryAfter)) {
            $details['retry_after'] = (int) $retryAfter;
        }

        foreach (['CF-Ray', 'X-Request-ID'] as $header) {
            $value = $response->header($header);
            if (preg_match('/^[a-zA-Z0-9._:-]{1,100}$/', $value)) {
                $details['request_id'] = $value;
                break;
            }
        }

        return $details;
    }

    /** @return array<string, string|int|float> */
    public static function exception(Throwable $exception): array
    {
        $details = ['exception_type' => class_basename($exception)];
        if ($exception instanceof RequestException) {
            $details += self::response($exception->response);
        }

        $reason = match (true) {
            $exception instanceof InsecureIntegrationEndpointException => 'https_required',
            $exception instanceof ConnectionException => 'connection_failed',
            $exception instanceof LockTimeoutException => 'lock_timeout',
            $exception instanceof RequestException => 'http_failed',
            default => 'unexpected_exception',
        };

        // Extract only the numeric transport code; exception messages can contain credentials or response bodies.
        if ($exception instanceof ConnectionException && preg_match('/cURL error (\d{1,3})\b/', $exception->getMessage(), $matches)) {
            $details['curl_code'] = (int) $matches[1];
            $reason = match ((int) $matches[1]) {
                6 => 'dns_failed',
                7 => 'connect_failed',
                28 => 'timeout',
                35 => 'tls_failed',
                60 => 'certificate_failed',
                default => $reason,
            };
            if ((int) $matches[1] === 28 && preg_match('/\bResolving timed out\b/i', $exception->getMessage())) {
                $reason = 'dns_timeout';
            }
        }

        return ['reason' => self::reason($reason), ...$details];
    }

    public static function reason(string $key): string
    {
        return __('admin_reports.reasons.'.$key, [], config('app.locale'));
    }

    public static function adminUrl(string $route): string
    {
        return rtrim((string) config('app.url'), '/').route($route, ['locale' => config('app.locale')], false);
    }
}
