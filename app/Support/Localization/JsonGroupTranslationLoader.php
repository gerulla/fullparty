<?php

namespace App\Support\Localization;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;

/** Makes the shared Vue dictionaries available to Laravel's grouped translator. */
final class JsonGroupTranslationLoader implements Loader
{
    public function __construct(
        private readonly Loader $loader,
        private readonly Filesystem $files,
        private readonly string $langPath,
    ) {}

    public function load($locale, $group, $namespace = null): array
    {
        $php = $this->loader->load($locale, $group, $namespace);

        if (($namespace !== null && $namespace !== '*')
            || ! preg_match('/^[a-zA-Z0-9_-]+$/', $locale)
            || ! preg_match('/^[a-zA-Z0-9_-]+$/', $group)) {
            return $php;
        }

        $base = $this->langPath.'/'.$locale.'/'.$group;
        $json = $this->files->exists($base.'.json') ? $this->read($base.'.json') : [];

        if ($this->files->isDirectory($base)) {
            foreach ($this->files->allFiles($base) as $file) {
                if ($file->getExtension() === 'json') {
                    $key = str_replace(['/', '\\'], '.', substr($file->getRelativePathname(), 0, -5));
                    Arr::set($json, $key, $this->read($file->getPathname()));
                }
            }
        }

        // Existing server-specific wording and Laravel placeholders take precedence.
        return array_replace_recursive($json, $php);
    }

    private function read(string $path): array
    {
        $messages = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        array_walk_recursive($messages, static function (&$value): void {
            if (is_string($value)) {
                $value = preg_replace('/(?<!\{)\{([a-zA-Z_][a-zA-Z0-9_]*)\}(?!\})/', ':$1', $value);
            }
        });

        return $messages;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->loader->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->loader->namespaces();
    }
}
