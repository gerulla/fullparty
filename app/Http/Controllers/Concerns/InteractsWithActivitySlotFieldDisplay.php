<?php

namespace App\Http\Controllers\Concerns;

use App\Models\CharacterClass;
use App\Models\PhantomJob;

trait InteractsWithActivitySlotFieldDisplay
{
    /**
     * @return array<string, string|null>|string|null
     */
    private function resolveSlotFieldDisplayValue($fieldValue)
    {
        if (!$fieldValue) {
            return null;
        }

        $meta = $this->resolveSlotFieldDisplayMeta($fieldValue);

        if (filled($meta['name'] ?? null)) {
            return (string) $meta['name'];
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
        if (!$fieldValue) {
            return null;
        }

        $value = $fieldValue->value;

        if (!is_array($value)) {
            return null;
        }

        if ($fieldValue->source === 'character_classes') {
            $classId = (int) ($value['id'] ?? 0);

            if ($classId <= 0) {
                return null;
            }

            static $classCache = [];

            if (!array_key_exists($classId, $classCache)) {
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

            if (!array_key_exists($phantomJobId, $phantomJobCache)) {
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

        if ($fieldValue->source === 'static_options') {
            return [
                'key' => $value['key'] ?? null,
                'label' => $value['label'] ?? null,
            ];
        }

        return null;
    }
}
