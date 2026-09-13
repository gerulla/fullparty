<?php

namespace App\Support\SeedData;

final class ActivityLocalizationCatalog
{
    private array $entries;

    public function __construct()
    {
        $catalog = json_decode(file_get_contents(database_path('data/activity-localizations.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->entries = $catalog['entries'];
        foreach ($this->entries as $entry) {
            $this->entries[$entry['translations']['en']] ??= $entry;
        }
    }

    /** Replace only known seed values, keeping custom wording and structural identifiers intact. */
    public function localize(array $value, bool $freshSeed = false): array
    {
        $english = $value['en'] ?? null;
        if (is_string($english) && preg_match('/^Fill in ([1-9][0-9]*)$/', $english, $match)) {
            foreach (['en', 'de', 'fr', 'ja'] as $locale) {
                if (blank($value[$locale] ?? null) || $value[$locale] === $english) {
                    $value[$locale] = __('ui.fill_in', ['number' => $match[1]], $locale);
                }
            }

            return $value;
        }
        if (is_string($english) && isset($this->entries[$english])) {
            $entry = $this->entries[$english];
            foreach ($entry['translations'] as $locale => $translation) {
                $current = $value[$locale] ?? null;
                if ($freshSeed || $current === null || $current === '' || $current === $english
                    || $current === ($entry['previous'][$locale] ?? null)) {
                    $value[$locale] = $translation;
                }
            }

            return $value;
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->localize($child, $freshSeed);
            }
        }

        return $value;
    }
}
