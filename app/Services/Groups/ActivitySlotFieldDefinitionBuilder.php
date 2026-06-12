<?php

namespace App\Services\Groups;

use App\Models\ActivityTypeVersion;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use Illuminate\Support\Str;

class ActivitySlotFieldDefinitionBuilder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(?ActivityTypeVersion $activityTypeVersion): array
    {
        return collect($activityTypeVersion?->slot_schema ?? [])
            ->map(function (array $field) use ($activityTypeVersion) {
                $applicationQuestion = $this->resolveApplicationQuestion($field, $activityTypeVersion);

                return [
                    'key' => (string) ($field['key'] ?? ''),
                    'application_key' => is_array($applicationQuestion) ? (string) ($applicationQuestion['key'] ?? '') : '',
                    'label' => is_array($field['label'] ?? null)
                        ? $field['label']
                        : ['en' => (string) ($field['key'] ?? '')],
                    'type' => (string) ($field['type'] ?? 'text'),
                    'source' => $field['source'] ?? null,
                    'options' => $this->resolveOptions($field),
                    'filter_options' => is_array($applicationQuestion)
                        ? $this->resolveOptions($applicationQuestion)
                        : [],
                ];
            })
            ->filter(fn (array $field) => $field['key'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveApplicationQuestion(array $slotField, ?ActivityTypeVersion $activityTypeVersion): ?array
    {
        $slotKey = (string) ($slotField['key'] ?? '');
        $slotSource = $slotField['source'] ?? null;
        $applicationSchema = collect($activityTypeVersion?->application_schema ?? [])
            ->filter(fn ($question) => is_array($question) && filled($question['key'] ?? null))
            ->values();

        if ($slotKey === '' || $applicationSchema->isEmpty()) {
            return null;
        }

        $exactMatch = $applicationSchema
            ->first(fn (array $question) => (string) ($question['key'] ?? '') === $slotKey);

        if (is_array($exactMatch)) {
            return $exactMatch;
        }

        $sourceAwareMatch = $applicationSchema->first(function (array $question) use ($slotKey, $slotSource) {
            if (($question['source'] ?? null) !== $slotSource) {
                return false;
            }

            return Str::contains((string) ($question['key'] ?? ''), $slotKey);
        });

        if (is_array($sourceAwareMatch)) {
            return $sourceAwareMatch;
        }

        $fallbackMatch = $applicationSchema
            ->first(fn (array $question) => ($question['source'] ?? null) === $slotSource);

        return is_array($fallbackMatch) ? $fallbackMatch : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveOptions(array $field): array
    {
        return match ($field['source'] ?? null) {
            'character_classes' => CharacterClass::query()
                ->orderBy('name')
                ->get()
                ->map(fn (CharacterClass $characterClass) => [
                    'key' => (string) $characterClass->id,
                    'label' => ['en' => $characterClass->name],
                    'meta' => [
                        'icon_url' => $characterClass->icon_url,
                        'flaticon_url' => $characterClass->flaticon_url,
                        'role' => $characterClass->role,
                        'shorthand' => $characterClass->shorthand,
                    ],
                ])
                ->values()
                ->all(),
            'phantom_jobs' => PhantomJob::query()
                ->orderBy('name')
                ->get()
                ->map(fn (PhantomJob $phantomJob) => [
                    'key' => (string) $phantomJob->id,
                    'label' => ['en' => $phantomJob->name],
                    'meta' => [
                        'icon_url' => $phantomJob->icon_url,
                        'black_icon_url' => $phantomJob->black_icon_url,
                        'transparent_icon_url' => $phantomJob->transparent_icon_url,
                        'sprite_url' => $phantomJob->sprite_url,
                    ],
                ])
                ->values()
                ->all(),
            'static_options' => collect($field['options'] ?? [])
                ->map(fn (array $option) => [
                    'key' => (string) ($option['key'] ?? $option['value'] ?? ''),
                    'label' => is_array($option['label'] ?? null)
                        ? $option['label']
                        : ['en' => (string) ($option['key'] ?? $option['value'] ?? '')],
                    'meta' => is_array($option['meta'] ?? null) ? $option['meta'] : null,
                ])
                ->filter(fn (array $option) => $option['key'] !== '')
                ->values()
                ->all(),
            default => [],
        };
    }
}
