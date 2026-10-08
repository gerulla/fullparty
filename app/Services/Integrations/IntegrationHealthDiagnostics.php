<?php

namespace App\Services\Integrations;

use Illuminate\Support\Str;

final class IntegrationHealthDiagnostics
{
    public function summary(?array $payload, bool $includeHealthy = false): string
    {
        $checks = $payload['checks'] ?? null;
        if (! is_array($checks)) {
            return '';
        }

        $summaries = [];
        foreach ($checks as $name => $check) {
            if (! is_array($check) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,49}$/', (string) $name)) {
                continue;
            }

            $status = is_string($check['status'] ?? null) ? strtolower($check['status']) : '';
            $healthy = in_array($status, ['', 'healthy', 'ok'], true) && ($check['ok'] ?? null) !== false && ($check['ready'] ?? null) !== false;
            if ($healthy && ! $includeHealthy) {
                continue;
            }

            $status = $healthy ? 'healthy' : (in_array($status, ['degraded', 'unhealthy', 'failed', 'error'], true) ? $status : 'unhealthy');
            $details = [];
            foreach (['warnCount', 'errorCount', 'ignoredCount', 'queued', 'processing', 'failedLastWindow', 'stuckProcessing', 'ping_ms'] as $field) {
                $value = $check[$field] ?? null;
                if (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                    $details[] = $this->label($field).': '.(0 + $value);
                }
            }
            if (is_bool($check['ready'] ?? null)) {
                $details[] = $this->label($check['ready'] ? 'ready' : 'not_ready');
            }
            if (is_string($check['lastFailureAt'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2}T[\d:.]+(?:Z|[+-]\d{2}:\d{2})$/', $check['lastFailureAt'])) {
                $details[] = $this->label('last_failure').': '.$check['lastFailureAt'];
            }

            $summaries[] = [
                'healthy' => $healthy,
                'text' => Str::headline($name).': '.__('admin_reports.health_status.'.$status, [], config('app.locale'))
                    .($details === [] ? '' : ' ('.implode(', ', $details).')'),
            ];
        }

        // Put the failing components first so Discord message limits preserve the useful evidence.
        return collect($summaries)->sortBy('healthy')->pluck('text')->implode('; ');
    }

    private function label(string $key): string
    {
        return __('admin_reports.health_metrics.'.$key, [], config('app.locale'));
    }
}
