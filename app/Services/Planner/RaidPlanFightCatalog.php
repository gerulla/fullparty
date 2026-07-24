<?php

namespace App\Services\Planner;

use App\Models\ActivityType;

class RaidPlanFightCatalog
{
    /**
     * @return array<int, array{
     *     id: int,
     *     slug: string,
     *     label: string,
     *     difficulty: string|null,
     *     image_url: string|null
     * }>
     */
    public function options(): array
    {
        return ActivityType::query()
            ->with('currentPublishedVersion:id,activity_type_id,name,small_image_url,difficulty')
            ->where('is_active', true)
            ->whereNotNull('current_published_version_id')
            ->orderBy('slug')
            ->get(['id', 'slug', 'current_published_version_id'])
            ->map(fn (ActivityType $activityType): array => [
                'id' => $activityType->id,
                'slug' => $activityType->slug,
                'label' => $this->localizedName(
                    $activityType->currentPublishedVersion?->name,
                    $activityType->slug,
                ),
                'difficulty' => $activityType->currentPublishedVersion?->difficulty,
                'image_url' => $activityType->currentPublishedVersion?->small_image_url,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>|null  $names
     */
    private function localizedName(?array $names, string $fallback): string
    {
        foreach ([app()->getLocale(), config('app.fallback_locale'), 'en'] as $locale) {
            if (is_string($locale) && filled($names[$locale] ?? null)) {
                return (string) $names[$locale];
            }
        }

        foreach ($names ?? [] as $name) {
            if (filled($name)) {
                return (string) $name;
            }
        }

        return $fallback;
    }
}
