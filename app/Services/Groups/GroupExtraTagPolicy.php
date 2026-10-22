<?php

namespace App\Services\Groups;

use App\Http\Middleware\ApplyLocale;
use App\Models\Group;
use App\Support\Input\TextInputSanitizer;

final class GroupExtraTagPolicy
{
    public function __construct(private readonly TextInputSanitizer $sanitizer) {}

    public function normalizedKey(string $tag): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($this->sanitizer->sanitizeSingleLine($tag))) ?? '';
    }

    /** @return list<string> */
    public function reservedKeys(): array
    {
        $labels = config('datacenters.values', []);
        $categories = [
            'groups.index.create_modal.fields.primary_focuses.options' => config('group_discovery.primary_focuses', []),
            'groups.index.create_modal.fields.experience_expectation.options' => config('group_discovery.experience_expectations', []),
            'groups.common.voice_expectations' => config('group_discovery.voice_expectations', []),
            'groups.common.language_names' => config('group_discovery.preferred_languages', []),
            'groups.common.group_types' => Group::TYPES,
            'groups.index.create_flow.datacenter.regions' => array_unique(array_values(config('datacenters.regions', []))),
        ];

        foreach ($categories as $namespace => $values) {
            foreach ($values as $value) {
                $labels[] = $value;
                foreach (ApplyLocale::SUPPORTED_LOCALES as $locale) {
                    $key = $namespace.'.'.$value;
                    $label = __($key, [], $locale);
                    if (is_string($label) && $label !== $key) {
                        $labels[] = $label;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map($this->normalizedKey(...), $labels))));
    }
}
