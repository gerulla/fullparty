<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BozjaHolster;
use App\Models\BozjaItem;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Models\RaidPosition;

trait InteractsWithActivitySlotFieldDisplay
{
    /**
     * @return array<int, mixed>
     */
    private function normalizeSelectableValues(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, function ($entry) {
                if (is_array($entry)) {
                    return filled($entry['id'] ?? null) || filled($entry['key'] ?? null);
                }

                return ! blank($entry);
            }));
        }

        return blank($value) ? [] : [$value];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveSelectionDisplayItems(?string $source, mixed $value): array
    {
        if ($source === 'bozja_holsters' && $this->isHolsterPairValue($value)) {
            return $this->resolveHolsterPairDisplayItems($value);
        }

        $values = $this->normalizeSelectableValues($value);

        if ($values === []) {
            return [];
        }

        if ($source === 'character_classes') {
            $classIds = collect($values)
                ->map(fn ($entry) => (int) (is_array($entry) ? ($entry['id'] ?? 0) : $entry))
                ->filter(fn (int $id) => $id > 0)
                ->values();

            if ($classIds->isEmpty()) {
                return [];
            }

            $classes = CharacterClass::query()
                ->select(['id', 'name', 'shorthand', 'icon_url', 'flaticon_url', 'role'])
                ->whereIn('id', $classIds->all())
                ->get()
                ->keyBy('id');

            return $classIds
                ->map(function (int $classId) use ($classes) {
                    /** @var CharacterClass|null $class */
                    $class = $classes->get($classId);

                    if (! $class) {
                        return null;
                    }

                    return [
                        'label' => $class->name,
                        'role' => $class->role,
                        'icon_url' => $class->icon_url,
                        'flaticon_url' => $class->flaticon_url,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        if ($source === 'phantom_jobs') {
            $phantomJobIds = collect($values)
                ->map(fn ($entry) => (int) (is_array($entry) ? ($entry['id'] ?? 0) : $entry))
                ->filter(fn (int $id) => $id > 0)
                ->values();

            if ($phantomJobIds->isEmpty()) {
                return [];
            }

            $phantomJobs = PhantomJob::query()
                ->select(['id', 'name', 'icon_url', 'black_icon_url', 'transparent_icon_url', 'sprite_url'])
                ->whereIn('id', $phantomJobIds->all())
                ->get()
                ->keyBy('id');

            return $phantomJobIds
                ->map(function (int $phantomJobId) use ($phantomJobs) {
                    /** @var PhantomJob|null $phantomJob */
                    $phantomJob = $phantomJobs->get($phantomJobId);

                    if (! $phantomJob) {
                        return null;
                    }

                    return [
                        'label' => $phantomJob->name,
                        'icon_url' => $phantomJob->icon_url,
                        'black_icon_url' => $phantomJob->black_icon_url,
                        'transparent_icon_url' => $phantomJob->transparent_icon_url,
                        'sprite_url' => $phantomJob->sprite_url,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        if ($source === 'bozja_holsters') {
            $holsterIds = collect($values)
                ->map(fn ($entry) => (int) (is_array($entry) ? ($entry['id'] ?? $entry['key'] ?? 0) : $entry))
                ->filter(fn (int $id) => $id > 0)
                ->values();
            $holsters = BozjaHolster::query()
                ->whereIn('id', $holsterIds->all())
                ->get()
                ->keyBy('id');

            return $holsterIds
                ->map(function (int $holsterId) use ($holsters) {
                    /** @var BozjaHolster|null $holster */
                    $holster = $holsters->get($holsterId);

                    return $holster ? [
                        'label' => $holster->localizedName(),
                        'max_capacity' => $holster->max_capacity,
                    ] : null;
                })
                ->filter()
                ->values()
                ->all();
        }

        if ($source === 'raid_positions') {
            $raidPositionKeys = collect($values)
                ->map(fn ($entry) => (string) (is_array($entry) ? ($entry['key'] ?? '') : $entry))
                ->filter(fn (string $key) => filled($key))
                ->values();

            if ($raidPositionKeys->isEmpty()) {
                return [];
            }

            $raidPositions = RaidPosition::query()
                ->select(['key', 'name', 'icon_url'])
                ->whereIn('key', $raidPositionKeys->all())
                ->get()
                ->keyBy('key');

            return $raidPositionKeys
                ->map(function (string $raidPositionKey) use ($raidPositions) {
                    /** @var RaidPosition|null $raidPosition */
                    $raidPosition = $raidPositions->get($raidPositionKey);

                    if (! $raidPosition) {
                        return null;
                    }

                    return [
                        'label' => $raidPosition->name,
                        'icon_url' => $raidPosition->icon_url,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        }

        if (BozjaItem::supportsSource($source)) {
            $itemIds = collect($values)
                ->map(fn ($entry) => (int) (is_array($entry) ? ($entry['id'] ?? $entry['key'] ?? 0) : $entry))
                ->filter(fn (int $id) => $id > 0)
                ->values();
            $items = BozjaItem::query()
                ->forSource((string) $source)
                ->whereIn('id', $itemIds->all())
                ->get()
                ->keyBy('id');

            return $itemIds
                ->map(function (int $itemId) use ($items) {
                    /** @var BozjaItem|null $item */
                    $item = $items->get($itemId);

                    return $item ? [
                        'label' => $item->localizedName(),
                        'icon_url' => $item->icon_url,
                        'classification' => $item->classification,
                        'cache_weight' => $item->cache_weight,
                    ] : null;
                })
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @return array<string, string|null>|string|null
     */
    private function resolveSlotFieldDisplayValue($fieldValue)
    {
        if (! $fieldValue) {
            return null;
        }

        $meta = $this->resolveSlotFieldDisplayMeta($fieldValue);
        $displayItems = $this->resolveSelectionDisplayItems($fieldValue->source, $fieldValue->value);

        if (filled($meta['name'] ?? null)) {
            return (string) $meta['name'];
        }

        if ($displayItems !== []) {
            return implode(', ', array_values(array_filter(array_map(
                fn (array $item) => filled($item['label'] ?? null) ? (string) $item['label'] : null,
                $displayItems,
            ))));
        }

        if (filled($meta['label'] ?? null)) {
            return $meta['label'];
        }

        $value = $fieldValue->value;

        if (is_array($value)) {
            if (filled($value['label'] ?? null)) {
                return $value['label'];
            }

            if (filled($value['name'] ?? null)) {
                return (string) $value['name'];
            }

            if (filled($value['key'] ?? null)) {
                return (string) $value['key'];
            }

            return null;
        }

        return filled($value) ? (string) $value : null;
    }

    private function resolveSlotFieldDisplayMeta($fieldValue): ?array
    {
        if (! $fieldValue) {
            return null;
        }

        $value = $fieldValue->value;

        if (! is_array($value)) {
            return null;
        }

        if ($fieldValue->source === 'character_classes') {
            $classId = (int) ($value['id'] ?? 0);

            if ($classId <= 0) {
                return null;
            }

            static $classCache = [];

            if (! array_key_exists($classId, $classCache)) {
                $classCache[$classId] = CharacterClass::query()
                    ->select(['id', 'name', 'shorthand', 'icon_url', 'flaticon_url', 'role'])
                    ->find($classId);
            }

            /** @var CharacterClass|null $class */
            $class = $classCache[$classId];

            return [
                'name' => $class?->name ?? ($value['name'] ?? null),
                'shorthand' => $class?->shorthand ?? ($value['shorthand'] ?? null),
                'role' => $class?->role ?? ($value['role'] ?? null),
                'icon_url' => $class?->icon_url,
                'flaticon_url' => $class?->flaticon_url,
            ];
        }

        if ($fieldValue->source === 'phantom_jobs') {
            $phantomJobId = (int) ($value['id'] ?? 0);

            if ($phantomJobId <= 0) {
                return null;
            }

            static $phantomJobCache = [];

            if (! array_key_exists($phantomJobId, $phantomJobCache)) {
                $phantomJobCache[$phantomJobId] = PhantomJob::query()
                    ->select(['id', 'name', 'icon_url', 'black_icon_url', 'transparent_icon_url', 'sprite_url'])
                    ->find($phantomJobId);
            }

            /** @var PhantomJob|null $phantomJob */
            $phantomJob = $phantomJobCache[$phantomJobId];

            return [
                'name' => $phantomJob?->name ?? ($value['name'] ?? null),
                'icon_url' => $phantomJob?->icon_url,
                'black_icon_url' => $phantomJob?->black_icon_url,
                'transparent_icon_url' => $phantomJob?->transparent_icon_url,
                'sprite_url' => $phantomJob?->sprite_url,
            ];
        }

        if ($fieldValue->source === 'bozja_holsters') {
            if ($this->isHolsterPairValue($value)) {
                $items = $this->resolveHolsterPairDisplayItems($value);
                $item = $items[0] ?? null;

                return $item ? [
                    'prepop_id' => (int) $value['prepop_id'],
                    'refill_id' => (int) $value['refill_id'],
                    'prepop_label' => $item['prepop_label'],
                    'refill_label' => $item['refill_label'],
                    'label' => $item['label'],
                ] : null;
            }

            $holsterId = (int) ($value['id'] ?? $value['key'] ?? 0);
            $holster = $holsterId > 0
                ? BozjaHolster::query()->find($holsterId)
                : null;

            return [
                'key' => $value['key'] ?? null,
                'label' => $holster?->name ?? ($value['label'] ?? null),
                'max_capacity' => $holster?->max_capacity,
            ];
        }

        if ($fieldValue->source === 'raid_positions' || $fieldValue->source === 'static_options') {
            return [
                'key' => $value['key'] ?? null,
                'label' => $value['label'] ?? null,
            ];
        }

        if (BozjaItem::supportsSource($fieldValue->source)) {
            $itemId = (int) ($value['id'] ?? $value['key'] ?? 0);
            $item = $itemId > 0
                ? BozjaItem::query()->find($itemId)
                : null;

            return [
                'key' => $value['key'] ?? null,
                'label' => $item?->name ?? ($value['label'] ?? null),
                'icon_url' => $item?->icon_url,
                'classification' => $item?->classification,
                'cache_weight' => $item?->cache_weight,
            ];
        }

        return null;
    }

    private function isHolsterPairValue(mixed $value): bool
    {
        return is_array($value)
            && ! array_is_list($value)
            && filled($value['prepop_id'] ?? null)
            && filled($value['refill_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<int, array<string, mixed>>
     */
    private function resolveHolsterPairDisplayItems(array $value): array
    {
        $prepopId = (int) ($value['prepop_id'] ?? 0);
        $refillId = (int) ($value['refill_id'] ?? 0);

        if ($prepopId <= 0 || $refillId <= 0) {
            return [];
        }

        $holsters = BozjaHolster::query()
            ->whereIn('id', [$prepopId, $refillId])
            ->get()
            ->keyBy('id');
        /** @var BozjaHolster|null $prepop */
        $prepop = $holsters->get($prepopId);
        /** @var BozjaHolster|null $refill */
        $refill = $holsters->get($refillId);

        $storedLabel = static function (mixed $label): ?string {
            if (is_string($label) && filled($label)) {
                return $label;
            }

            if (! is_array($label)) {
                return null;
            }

            return collect([
                $label[app()->getLocale()] ?? null,
                $label[config('app.fallback_locale', 'en')] ?? null,
                $label['en'] ?? null,
                ...array_values($label),
            ])->first(fn (mixed $entry): bool => is_string($entry) && filled($entry));
        };
        $prepopLabel = $prepop?->localizedName() ?? $storedLabel($value['prepop_label'] ?? null);
        $refillLabel = $refill?->localizedName() ?? $storedLabel($value['refill_label'] ?? null);

        if (! $prepopLabel || ! $refillLabel) {
            return [];
        }

        return [[
            'label' => $prepopLabel.' + '.$refillLabel,
            'prepop_label' => $prepopLabel,
            'refill_label' => $refillLabel,
        ]];
    }
}
