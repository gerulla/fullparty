<?php

namespace App\Services\Groups\ApplicantQueue;

use App\Models\ActivityTypeVersion;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use Illuminate\Support\Collection;

class ApplicationAnswerPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public function present($answer, ?ActivityTypeVersion $activityTypeVersion): ?array
    {
        $questionDefinition = collect($activityTypeVersion?->application_schema ?? [])
            ->first(fn ($question) => ($question['key'] ?? null) === $answer->question_key);

        if (!is_array($questionDefinition)) {
            return null;
        }

        $displayValues = $this->resolveDisplayValues($answer->source, $answer->value, $questionDefinition);

        return [
            'question_key' => $answer->question_key,
            'question_label' => is_array($questionDefinition['label'] ?? null)
                ? $questionDefinition['label']
                : ['en' => $answer->question_key],
            'question_type' => (string) ($questionDefinition['type'] ?? 'text'),
            'source' => $answer->source,
            'raw_value' => $answer->value,
            'display_values' => $displayValues->values()->all(),
            'role_values' => $this->resolveRoleValues($answer->source, $answer->value)->values()->all(),
            'display_items' => $this->resolveDisplayItems($answer->source, $answer->value)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $questionDefinition
     * @return Collection<int, string>
     */
    private function resolveDisplayValues(?string $source, mixed $value, ?array $questionDefinition): Collection
    {
        $values = is_array($value) ? collect($value)->values() : collect([$value])->filter(fn ($entry) => !blank($entry));

        if ($values->isEmpty()) {
            return collect();
        }

        if ($source === 'character_classes') {
            $labels = CharacterClass::query()
                ->whereIn('id', $values->map(fn ($entry) => (int) $entry)->all())
                ->pluck('name', 'id');

            return $values
                ->map(fn ($entry) => $labels[(int) $entry] ?? null)
                ->filter();
        }

        if ($source === 'phantom_jobs') {
            $labels = PhantomJob::query()
                ->whereIn('id', $values->map(fn ($entry) => (int) $entry)->all())
                ->pluck('name', 'id');

            return $values
                ->map(fn ($entry) => $labels[(int) $entry] ?? null)
                ->filter();
        }

        if ($source === 'static_options') {
            $options = collect($questionDefinition['options'] ?? [])
                ->filter(fn ($option) => is_array($option) && filled($option['value'] ?? null))
                ->keyBy(fn (array $option) => (string) $option['value']);

            return $values
                ->map(function ($entry) use ($options) {
                    $option = $options->get((string) $entry);

                    if (!is_array($option)) {
                        return (string) $entry;
                    }

                    $label = $option['label'] ?? null;

                    if (is_array($label)) {
                        return (string) ($label['en'] ?? reset($label) ?: $entry);
                    }

                    return (string) $entry;
                })
                ->filter();
        }

        return $values
            ->map(fn ($entry) => is_bool($entry) ? ($entry ? 'Yes' : 'No') : (string) $entry)
            ->filter(fn ($entry) => $entry !== '');
    }

    /**
     * @return Collection<int, string>
     */
    private function resolveRoleValues(?string $source, mixed $value): Collection
    {
        if ($source !== 'character_classes') {
            return collect();
        }

        $values = is_array($value) ? collect($value)->values() : collect([$value])->filter(fn ($entry) => !blank($entry));

        if ($values->isEmpty()) {
            return collect();
        }

        return CharacterClass::query()
            ->whereIn('id', $values->map(fn ($entry) => (int) $entry)->all())
            ->pluck('role')
            ->filter()
            ->map(function (string $role) {
                return match ($role) {
                    'tank' => 'Tank',
                    'healer' => 'Healer',
                    'physical ranged dps' => 'Phys Ranged',
                    'magic ranged dps' => 'Magic Ranged',
                    'melee dps' => 'Melee',
                    default => $role,
                };
            })
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveDisplayItems(?string $source, mixed $value): Collection
    {
        $values = is_array($value) ? collect($value)->values() : collect([$value])->filter(fn ($entry) => !blank($entry));

        if ($values->isEmpty()) {
            return collect();
        }

        if ($source === 'character_classes') {
            $classes = CharacterClass::query()
                ->whereIn('id', $values->map(fn ($entry) => (int) $entry)->all())
                ->get()
                ->keyBy('id');

            return $values
                ->map(function ($entry) use ($classes) {
                    /** @var CharacterClass|null $class */
                    $class = $classes->get((int) $entry);

                    if (!$class) {
                        return null;
                    }

                    return [
                        'label' => $class->name,
                        'role' => $class->role,
                        'icon_url' => $class->icon_url,
                        'flat_icon_url' => $class->flaticon_url,
                    ];
                })
                ->filter()
                ->values();
        }

        if ($source === 'phantom_jobs') {
            $phantomJobs = PhantomJob::query()
                ->whereIn('id', $values->map(fn ($entry) => (int) $entry)->all())
                ->get()
                ->keyBy('id');

            return $values
                ->map(function ($entry) use ($phantomJobs) {
                    /** @var PhantomJob|null $phantomJob */
                    $phantomJob = $phantomJobs->get((int) $entry);

                    if (!$phantomJob) {
                        return null;
                    }

                    return [
                        'label' => $phantomJob->name,
                        'icon_url' => $phantomJob->icon_url,
                        'transparent_icon_url' => $phantomJob->transparent_icon_url,
                    ];
                })
                ->filter()
                ->values();
        }

        return collect();
    }
}
