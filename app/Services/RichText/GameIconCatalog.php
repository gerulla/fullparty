<?php

namespace App\Services\RichText;

use App\Support\SeedData\ReferenceIconCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** The editor catalog comes exclusively from bundled assets, never the game APIs. */
class GameIconCatalog
{
    private ?array $entries = null;

    public function all(): array
    {
        return array_values($this->indexed());
    }

    public function find(string $key): ?array
    {
        return $this->indexed()[$key] ?? null;
    }

    private function indexed(): array
    {
        return $this->entries ??= Cache::remember('rich-text.game-icons.v1', now()->addDay(), fn () => $this->build());
    }

    private function build(): array
    {
        $entries = [];
        $translations = [];
        foreach (['en', 'de', 'fr', 'ja'] as $locale) {
            $translations[$locale] = [
                'characters' => $this->json(lang_path("{$locale}/characters.json")),
                'general' => $this->json(lang_path("{$locale}/general.json")),
            ];
        }
        foreach (ReferenceIconCatalog::characterClasses() as $job) {
            $code = strtolower($job['shorthand']);
            $names = $this->translatedNames($translations, "characters.jobs.classes.{$code}", $job['name']);
            $path = $this->firstFile([$job['icon_reference_path'], $job['icon_public_path']]);
            $this->add($entries, "class_{$code}", $code, 'class', $path, $names, [$job['name']]);
        }
        foreach (ReferenceIconCatalog::phantomJobs() as $job) {
            $name = Str::after($job['name'], 'Phantom ');
            $slug = Str::slug($name, '_');
            $names = $this->translatedNames($translations, "characters.jobs.phantom.{$slug}", $job['name']);
            $path = $this->firstFile([$job['icon_reference_path'], $job['icon_public_path'], 'newjobs/'.Str::slug($job['name']).'/blue.png']);
            $this->add($entries, "phantom_{$slug}", "phantom_{$slug}", 'phantom_job', $path, $names, [$name, "ph_{$slug}", "ph{$slug}"]);
        }
        foreach ([
            'tank' => ['tank', 'tank', []],
            'healer' => ['healer', 'healer', ['heal']],
            'dps' => ['dps', null, ['damage']],
            'melee' => ['melee_dps', 'melee_dps', ['melee_dps']],
            'physrange' => ['physrange_dps', 'physical_ranged_dps', ['physranged', 'physical_ranged', 'physical_ranged_dps', 'physrange_dps']],
            'magicrange' => ['magic_range_dps', 'magic_ranged_dps', ['caster', 'magical_ranged', 'magic_ranged_dps', 'magic_range_dps']],
        ] as $code => [$file, $translation, $aliases]) {
            $names = $this->translatedNames($translations, "general.roles.{$translation}", 'DPS');
            $this->add($entries, "role_{$code}", $code, 'role', $this->firstFile(["role-icons/{$file}.png"]), $names, $aliases);
        }

        $directory = public_path('CalculatorData');
        $paths = [];
        if (is_dir($directory)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getFilename() === 'info.json') {
                    $paths[] = $file->getPathname();
                }
            }
        }
        sort($paths);
        foreach ($paths as $path) {
            $data = $this->json($path);
            if (($data['kind'] ?? '') !== 'ability' || empty($data['id'])) {
                continue;
            }
            $key = 'action_'.$data['id'];
            // Role actions are repeated in each eligible job's folder.
            if (isset($entries[$key])) {
                continue;
            }
            $names = ['en' => $data['name']];
            foreach (['de', 'fr', 'ja'] as $locale) {
                $names[$locale] = $this->json(dirname($path)."/info.{$locale}.json")['name'] ?? $names['en'];
            }
            $roleAction = (bool) data_get($data, 'metadata.is_role_action');
            $category = $roleAction ? 'role_action' : (! empty($data['is_phantom_action']) ? 'phantom_action' : 'class_action');
            $job = strtolower(data_get($data, 'job.abbreviation') ?: Str::slug(data_get($data, 'job.name', ''), '_'));
            $shortcode = ($roleAction ? '' : Str::slug($job, '_').'_').Str::slug($names['en'], '_');
            // IDs keep variants with the same in-game name independently selectable.
            if (in_array($shortcode, array_column($entries, 'shortcode'), true)) {
                $shortcode .= '_'.$data['id'];
            }
            $icon = str_replace('\\', '/', substr(dirname($path), strlen(public_path()) + 1)).'/icon.png';
            $this->add($entries, $key, $shortcode, $category, $this->firstFile([$icon]), $names, [], $roleAction ? '' : strtoupper($job));
        }
        foreach (glob(public_path('BozjaInfo/*/info.json')) ?: [] as $path) {
            $data = $this->json($path);
            $english = $data['en'] ?? [];
            $id = data_get($english, 'cache.item_id');
            if (! $id || empty($english['title'])) {
                continue;
            }
            $names = [];
            foreach (['en', 'de', 'fr', 'ja'] as $locale) {
                $names[$locale] = $data[$locale]['title'] ?? $english['title'];
            }
            $icon = 'BozjaInfo/'.basename(dirname($path)).'/icon.png';
            $category = ($english['classification'] ?? '') === 'lost_action' ? 'bozja_action' : 'bozja_item';
            $this->add($entries, 'bozja_'.$id, Str::slug($english['title'], '_'), $category, $this->firstFile([$icon]), $names);
        }

        return $entries;
    }

    private function add(array &$entries, string $key, string $shortcode, string $category, ?string $path, array $names, array $aliases = [], string $job = ''): void
    {
        if ($path === null) {
            return;
        }
        $aliases = array_values(array_unique(array_filter(array_map(
            fn ($name) => trim(preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_strtolower($name)), '_'),
            [$shortcode, ...$aliases, ...array_values($names)],
        ))));
        $entries[$key] = compact('key', 'shortcode', 'category', 'names', 'aliases', 'job') + [
            'src' => '/'.implode('/', array_map('rawurlencode', explode('/', $path))),
        ];
    }

    private function translatedNames(array $translations, string $key, string $fallback): array
    {
        return array_map(fn ($locale) => data_get($locale, $key, $fallback), $translations);
    }

    private function firstFile(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file(public_path($path))) {
                return $path;
            }
        }

        return null;
    }

    private function json(string $path): array
    {
        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }
}
