<?php

namespace App\Services\Groups\ApplicantQueue;

use App\Models\ActivityTypeVersion;
use App\Models\BozjaItem;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Models\RaidPosition;
use Illuminate\Support\Collection;

class ApplicationAnswerPresenter
{
    private const ANY_OPTION_KEY = 'any';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function presentDisplayItems(?string $source, mixed $value): array
    {
        return $this->resolveDisplayItems($source, $value, null)->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function present($answer, ?ActivityTypeVersion $activityTypeVersion): ?array
    {
        $questionDefinition = collect($activityTypeVersion?->application_schema ?? [])
            ->first(fn ($question) => ($question['key'] ?? null) === $answer->question_key);

        if (! is_array($questionDefinition)) {
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
            'display_items' => $this->resolveDisplayItems($answer->source, $answer->value, $questionDefinition)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $questionDefinition
     * @return Collection<int, string>
     */
    private function resolveDisplayValues(?string $source, mixed $value, ?array $questionDefinition): Collection
    {
        $values = is_array($value) ? collect($value)->values() : collect([$value])->filter(fn ($entry) => ! blank($entry));

        if ($values->isEmpty()) {
            return collect();
        }

        $anyLabel = $this->anyDisplayLabel($questionDefinition);

        if ($source === 'character_classes') {
            $labels = CharacterClass::query()
                ->whereIn('id', $values->reject(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY)->map(fn ($entry) => (int) $entry)->all())
                ->pluck('name', 'id');

            return $values
                ->map(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY && $anyLabel !== null
                    ? $anyLabel
                    : $labels[(int) $entry] ?? null)
                ->filter();
        }

        if ($source === 'phantom_jobs') {
            $labels = PhantomJob::query()
                ->whereIn('id', $values->reject(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY)->map(fn ($entry) => (int) $entry)->all())
                ->pluck('name', 'id');

            return $values
                ->map(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY && $anyLabel !== null
                    ? $anyLabel
                    : $labels[(int) $entry] ?? null)
                ->filter();
        }

        if ($source === 'raid_positions') {
            $labels = RaidPosition::query()
                ->whereIn('key', $values->reject(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY)->map(fn ($entry) => (string) $entry)->all())
                ->pluck('name', 'key');

            return $values
                ->map(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY && $anyLabel !== null
                    ? $anyLabel
                    : $labels[(string) $entry] ?? null)
                ->filter();
        }

        if (BozjaItem::supportsSource($source)) {
            $items = BozjaItem::query()
                ->forSource((string) $source)
                ->whereIn('id', $values->reject(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY)->map(fn ($entry) => (int) $entry)->all())
                ->get()
                ->keyBy('id');

            return $values
                ->map(function ($entry) use ($items, $anyLabel) {
                    if ((string) $entry === self::ANY_OPTION_KEY && $anyLabel !== null) {
                        return $anyLabel;
                    }

                    return $items->get((int) $entry)?->localizedName();
                })
                ->filter();
        }

        if ($source === 'static_options') {
            $options = $this->optionDefinitions($questionDefinition)
                ->keyBy(fn (array $option) => (string) ($option['value'] ?? $option['key'] ?? ''));

            return $values
                ->map(function ($entry) use ($options) {
                    $option = $options->get((string) $entry);

                    if (! is_array($option)) {
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

        $values = is_array($value) ? collect($value)->values() : collect([$value])->filter(fn ($entry) => ! blank($entry));

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
    private function resolveDisplayItems(?string $source, mixed $value, ?array $questionDefinition): Collection
    {
        $values = is_array($value) ? collect($value)->values() : collect([$value])->filter(fn ($entry) => ! blank($entry));

        if ($values->isEmpty()) {
            return collect();
        }

        $anyLabel = $this->anyDisplayLabel($questionDefinition);

        if ($source === 'character_classes') {
            $classes = CharacterClass::query()
                ->whereIn('id', $values->reject(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY)->map(fn ($entry) => (int) $entry)->all())
                ->get()
                ->keyBy('id');

            return $values
                ->map(function ($entry) use ($classes, $anyLabel) {
                    if ((string) $entry === self::ANY_OPTION_KEY && $anyLabel !== null) {
                        return [
                            'label' => $anyLabel,
                            'role' => null,
                            'icon_url' => null,
                            'flat_icon_url' => null,
                            'is_any' => true,
                        ];
                    }

                    /** @var CharacterClass|null $class */
                    $class = $classes->get((int) $entry);

                    if (! $class) {
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
                ->whereIn('id', $values->reject(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY)->map(fn ($entry) => (int) $entry)->all())
                ->get()
                ->keyBy('id');

            return $values
                ->map(function ($entry) use ($phantomJobs, $anyLabel) {
                    if ((string) $entry === self::ANY_OPTION_KEY && $anyLabel !== null) {
                        return [
                            'label' => $anyLabel,
                            'icon_url' => null,
                            'transparent_icon_url' => null,
                            'is_any' => true,
                        ];
                    }

                    /** @var PhantomJob|null $phantomJob */
                    $phantomJob = $phantomJobs->get((int) $entry);

                    if (! $phantomJob) {
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

        if ($source === 'raid_positions') {
            $raidPositions = RaidPosition::query()
                ->whereIn('key', $values->reject(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY)->map(fn ($entry) => (string) $entry)->all())
                ->get()
                ->keyBy('key');

            return $values
                ->map(function ($entry) use ($raidPositions, $anyLabel) {
                    if ((string) $entry === self::ANY_OPTION_KEY && $anyLabel !== null) {
                        return [
                            'label' => $anyLabel,
                            'icon_url' => null,
                            'is_any' => true,
                        ];
                    }

                    /** @var RaidPosition|null $raidPosition */
                    $raidPosition = $raidPositions->get((string) $entry);

                    if (! $raidPosition) {
                        return null;
                    }

                    return [
                        'label' => $raidPosition->name,
                        'icon_url' => $raidPosition->icon_url,
                    ];
                })
                ->filter()
                ->values();
        }

        if (BozjaItem::supportsSource($source)) {
            $items = BozjaItem::query()
                ->forSource((string) $source)
                ->whereIn('id', $values->reject(fn ($entry) => (string) $entry === self::ANY_OPTION_KEY)->map(fn ($entry) => (int) $entry)->all())
                ->get()
                ->keyBy('id');

            return $values
                ->map(function ($entry) use ($items, $anyLabel) {
                    if ((string) $entry === self::ANY_OPTION_KEY && $anyLabel !== null) {
                        return [
                            'label' => $anyLabel,
                            'icon_url' => null,
                            'is_any' => true,
                        ];
                    }

                    /** @var BozjaItem|null $item */
                    $item = $items->get((int) $entry);

                    if (! $item) {
                        return null;
                    }

                    return [
                        'label' => $item->localizedName(),
                        'icon_url' => $item->icon_url,
                        'classification' => $item->classification,
                        'cache_weight' => $item->cache_weight,
                    ];
                })
                ->filter()
                ->values();
        }

        return collect();
    }

    /**
     * @param  array<string, mixed>|null  $questionDefinition
     * @return Collection<int, array<string, mixed>>
     */
    private function optionDefinitions(?array $questionDefinition): Collection
    {
        $options = collect($questionDefinition['options'] ?? [])
            ->filter(fn ($option) => is_array($option) && filled($option['value'] ?? $option['key'] ?? null))
            ->values();

        if (! $this->supportsAnyOption($questionDefinition)) {
            return $options;
        }

        if ($options->contains(fn (array $option) => (string) ($option['value'] ?? $option['key'] ?? '') === self::ANY_OPTION_KEY)) {
            return $options;
        }

        return $options
            ->push([
                'value' => self::ANY_OPTION_KEY,
                'key' => self::ANY_OPTION_KEY,
                'label' => is_array($questionDefinition['any_label'] ?? null)
                    ? $questionDefinition['any_label']
                    : ['en' => 'Any'],
            ]);
    }

    /**
     * @param  array<string, mixed>|null  $questionDefinition
     */
    private function anyDisplayLabel(?array $questionDefinition): ?string
    {
        if (! $this->supportsAnyOption($questionDefinition)) {
            return null;
        }

        $label = $questionDefinition['any_label'] ?? null;

        if (! is_array($label)) {
            return 'Any';
        }

        return (string) ($label['en'] ?? reset($label) ?: 'Any');
    }

    /**
     * @param  array<string, mixed>|null  $questionDefinition
     */
    private function supportsAnyOption(?array $questionDefinition): bool
    {
        return (bool) ($questionDefinition['accepts_any'] ?? false)
            && in_array((string) ($questionDefinition['type'] ?? ''), ['single_select', 'multi_select'], true);
    }
}
