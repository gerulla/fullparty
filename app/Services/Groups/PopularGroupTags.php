<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Support\Input\TextInputSanitizer;

final class PopularGroupTags
{
    public function __construct(
        private readonly TextInputSanitizer $sanitizer,
        private readonly GroupExtraTagPolicy $tagPolicy,
    ) {}

    /** @return list<string> */
    public function suggestions(int $limit = 8): array
    {
        $tags = [];
        $reservedKeys = array_fill_keys($this->tagPolicy->reservedKeys(), true);

        foreach (Group::query()->visible()->select(['id', 'tags'])->whereNotNull('tags')->lazyById(500) as $group) {
            $seen = [];
            foreach ($group->tags ?? [] as $tag) {
                if (! is_string($tag)) {
                    continue;
                }

                $label = $this->sanitizer->sanitizeSingleLine($tag);
                $key = mb_strtolower($label ?? '');
                if ($key === '' || mb_strlen($label) > 50 || isset($seen[$key]) || isset($reservedKeys[$this->tagPolicy->normalizedKey($label)])) {
                    continue;
                }

                $seen[$key] = true;
                $tags[$key] ??= ['label' => $label, 'count' => 0, 'key' => $key];
                $tags[$key]['count']++;
            }
        }

        return collect($tags)
            ->sort(fn (array $left, array $right) => ($right['count'] <=> $left['count']) ?: strcmp($left['key'], $right['key']))
            ->take(max(0, $limit))
            ->pluck('label')
            ->all();
    }
}
