<?php

namespace App\Services\Audit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class AuditValuePresenter
{
    public function label(string $key): string
    {
        $translation = 'audit_log.fields.'.$key;
        $label = __($translation);

        return $label !== $translation ? $label : Str::headline($key);
    }

    public function value(string $key, mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['type' => 'empty'];
        }

        if (is_bool($value)) {
            return ['type' => 'boolean', 'value' => $value];
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return ['type' => 'list', 'value' => array_map(fn ($item) => $this->value($key, $item), $value)];
            }

            $fields = [];
            foreach ($value as $field => $item) {
                if (! $this->isSensitive((string) $field)) {
                    $fields[] = ['label' => $this->label((string) $field), 'value' => $this->value((string) $field, $item)];
                }
            }

            return ['type' => 'object', 'value' => $fields];
        }

        if ($key === 'duration_hours' && is_numeric($value)) {
            return ['type' => 'duration', 'value' => (float) $value];
        }

        if (is_string($value) && str_ends_with($key, '_at') && preg_match('/^\d{4}-\d{2}-\d{2}[T ]/', $value)) {
            try {
                return ['type' => 'datetime', 'value' => Carbon::parse($value)->toIso8601String()];
            } catch (\Throwable) {
                // Keep the original recorded value when legacy metadata is malformed.
            }
        }

        return ['type' => 'text', 'value' => $this->text($key, $value)];
    }

    public function text(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('audit_log.defaults.empty');
        }

        $namespace = match ($key) {
            'role', 'previous_role' => 'groups.common.roles.',
            'join_mode' => 'groups.common.join_modes.',
            'group_type' => 'groups.common.group_types.',
            'status' => 'groups.activities.statuses.',
            'resource_status' => 'groups.resources.workspace.',
            'application_status' => 'runs.participation.statuses.',
            'voice_expectation' => 'groups.common.voice_expectations.',
            'active_days' => 'groups.common.active_days.',
            default => null,
        };
        if ($namespace) {
            $translation = $namespace.$value.($key === 'join_mode' ? '.label' : '');
            $label = __($translation);
            if ($label !== $translation) {
                return $label;
            }
        }

        if (is_numeric($value) && str_ends_with($key, '_id')) {
            return '#'.$value;
        }

        return trim((string) $value);
    }

    public function isSensitive(string $key): bool
    {
        return (bool) preg_match('/(?:token|secret|password|access_key|credential)/i', $key);
    }
}
