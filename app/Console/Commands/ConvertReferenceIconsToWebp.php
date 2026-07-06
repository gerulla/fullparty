<?php

namespace App\Console\Commands;

use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Support\SeedData\ReferenceIconCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class ConvertReferenceIconsToWebp extends Command
{
    private const WEBP_QUALITY = 84;

    protected $signature = 'reference-icons:convert-webp
                            {--dry-run : Report conversions without writing files or updating records}
                            {--force : Recreate WebP files even when the destination already exists}
                            {--only= : Limit conversion to character-classes or phantom-jobs}
                            {--source-root= : Local source root for UUID storage files}
                            {--target-root=public/reference-icons : Git-tracked target directory for WebP assets}';

    protected $description = 'Promote local character class and phantom job icon files to tracked WebP assets and update their database references.';

    /**
     * @var array{converted: int, updated: int, skipped: int, missing: int, failed: int}
     */
    private array $totals = [
        'converted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'missing' => 0,
        'failed' => 0,
    ];

    private string $sourceRoot;

    private string $targetRoot;

    public function handle(): int
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            $this->error('GD image processing with WebP support is not available.');

            return self::FAILURE;
        }

        $this->sourceRoot = $this->resolvePath((string) ($this->option('source-root') ?: 'storage/app/public'));
        $this->targetRoot = $this->resolvePath((string) $this->option('target-root'));

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $only = (string) ($this->option('only') ?? '');

        if (! in_array($only, ['', 'character-classes', 'phantom-jobs'], true)) {
            $this->error('The --only option must be character-classes or phantom-jobs.');

            return self::FAILURE;
        }

        if ($only !== 'phantom-jobs') {
            $characterClassPaths = $this->characterClassPaths();

            CharacterClass::query()
                ->orderBy('id')
                ->each(function (CharacterClass $characterClass) use ($dryRun, $force, $characterClassPaths): void {
                    $label = sprintf('Class %s', $characterClass->shorthand);
                    $paths = $characterClassPaths[$characterClass->shorthand] ?? [];

                    $this->convertModelField($characterClass, 'icon_url', "{$label} icon", $paths['icon_url'] ?? null, $dryRun, $force);
                    $this->convertModelField($characterClass, 'flaticon_url', "{$label} flat icon", $paths['flaticon_url'] ?? null, $dryRun, $force);
                });
        }

        if ($only !== 'character-classes') {
            $phantomJobPaths = $this->phantomJobPaths();

            PhantomJob::query()
                ->orderBy('id')
                ->each(function (PhantomJob $phantomJob) use ($dryRun, $force, $phantomJobPaths): void {
                    $label = sprintf('Phantom job %s', $phantomJob->name);
                    $paths = $phantomJobPaths[$phantomJob->name] ?? [];

                    $this->convertModelField($phantomJob, 'icon_url', "{$label} icon", $paths['icon_url'] ?? null, $dryRun, $force);
                    $this->convertModelField($phantomJob, 'black_icon_url', "{$label} black icon", $paths['black_icon_url'] ?? null, $dryRun, $force);
                    $this->convertModelField($phantomJob, 'transparent_icon_url', "{$label} transparent icon", $paths['transparent_icon_url'] ?? null, $dryRun, $force);
                    $this->convertModelField($phantomJob, 'sprite_url', "{$label} sprite", $paths['sprite_url'] ?? null, $dryRun, $force);
                });
        }

        $this->newLine();
        $this->info(sprintf('%s: %d', $dryRun ? 'Would convert' : 'Converted', $this->totals['converted']));
        $this->line(sprintf('%s: %d', $dryRun ? 'Would update references' : 'Updated references', $this->totals['updated']));
        $this->line(sprintf('Already WebP / blank / unchanged: %d', $this->totals['skipped']));
        $this->line(sprintf('Missing local source files: %d', $this->totals['missing']));
        $this->line(sprintf('Failed: %d', $this->totals['failed']));

        return $this->totals['failed'] === 0 && $this->totals['missing'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array{reference_path: string, seed_public_path: string, storage_source_path: string|null, source_candidates?: array<int, string>}|null  $paths
     */
    private function convertModelField(
        Model $model,
        string $field,
        string $label,
        ?array $paths,
        bool $dryRun,
        bool $force,
    ): void {
        if (! $paths) {
            $this->totals['missing']++;
            $this->warn(sprintf('Missing catalog entry for %s', $label));

            return;
        }

        $url = $model->getAttribute($field);

        if (! is_string($url) || blank($url)) {
            $this->totals['skipped']++;

            return;
        }

        $newUrl = '/'.ltrim($paths['reference_path'], '/');
        $targetPath = $this->targetPath($paths['reference_path']);
        $sourcePath = $this->sourcePath($url, $paths);

        if (! is_file($targetPath) && ! $sourcePath) {
            $this->totals['missing']++;
            $this->warn(sprintf('Missing local source for %s', $label));

            return;
        }

        $shouldConvert = (! is_file($targetPath) || $force) && $sourcePath;

        $converted = false;

        if ($shouldConvert && $dryRun) {
            $this->totals['converted']++;
            $converted = true;
            $this->line(sprintf('<info>Would convert</info> %s -> %s', $label, $newUrl));
        }

        if ($shouldConvert && ! $dryRun) {
            if (! $this->convertImage($sourcePath, $targetPath, $label)) {
                return;
            }

            $this->totals['converted']++;
            $converted = true;
            $this->line(sprintf('<info>Converted</info> %s -> %s', $label, $newUrl));
        }

        if ($url !== $newUrl) {
            $this->totals['updated']++;

            if (! $dryRun) {
                $model->forceFill([$field => $newUrl])->save();
            }

            return;
        }

        if ($converted) {
            return;
        }

        $this->totals['skipped']++;
    }

    /**
     * @param  array{reference_path: string, seed_public_path: string, storage_source_path: string|null, source_candidates?: array<int, string>}  $paths
     */
    private function sourcePath(string $currentUrl, array $paths): ?string
    {
        $currentPath = $this->localPathFromUrl($currentUrl);

        if ($currentPath && is_file($currentPath)) {
            return $currentPath;
        }

        foreach ($paths['source_candidates'] ?? [] as $sourceCandidate) {
            foreach ([$this->sourceRoot, $this->targetRoot] as $root) {
                $candidatePath = rtrim($root, '\\/').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($sourceCandidate, '\\/'));

                if (is_file($candidatePath)) {
                    return $candidatePath;
                }
            }
        }

        if ($paths['storage_source_path']) {
            $storageSource = $this->sourceRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $paths['storage_source_path']);

            if (is_file($storageSource)) {
                return $storageSource;
            }
        }

        $seedPath = public_path($paths['seed_public_path']);

        return is_file($seedPath) ? $seedPath : null;
    }

    private function localPathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($host) && $host !== '' && is_string($appHost) && $appHost !== '' && ! hash_equals($appHost, $host)) {
            return null;
        }

        $relativePath = ltrim(urldecode($path), '/\\');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        if (str_starts_with($relativePath, 'storage/')) {
            return storage_path('app/public/'.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, substr($relativePath, strlen('storage/'))));
        }

        return public_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
    }

    private function targetPath(string $referencePath): string
    {
        $relativePath = preg_replace('#^reference-icons[\\\\/]?#', '', $referencePath) ?: $referencePath;

        return rtrim($this->targetRoot, '\\/').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '\\/'));
    }

    private function convertImage(string $sourcePath, string $targetPath, string $label): bool
    {
        $binary = file_get_contents($sourcePath);

        if ($binary === false) {
            $this->totals['failed']++;
            $this->error(sprintf('Unable to read %s', $sourcePath));

            return false;
        }

        $image = @imagecreatefromstring($binary);

        if (! $image) {
            $this->totals['failed']++;
            $this->error(sprintf('Unable to decode image for %s', $label));

            return false;
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $directory = dirname($targetPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            imagedestroy($image);
            $this->totals['failed']++;
            $this->error(sprintf('Unable to create %s', $directory));

            return false;
        }

        $result = imagewebp($image, $targetPath, self::WEBP_QUALITY);
        imagedestroy($image);

        if (! $result) {
            $this->totals['failed']++;
            $this->error(sprintf('Unable to write %s', $targetPath));

            return false;
        }

        return true;
    }

    /**
     * @return array<string, array{icon_url: array{reference_path: string, seed_public_path: string, storage_source_path: string|null, source_candidates: array<int, string>}, flaticon_url: array{reference_path: string, seed_public_path: string, storage_source_path: string|null, source_candidates: array<int, string>}}>
     */
    private function characterClassPaths(): array
    {
        $classes = ReferenceIconCatalog::characterClasses();
        $iconStoragePaths = $this->orderedStoragePaths('character-classes/icons', count($classes));
        $flatIconStoragePaths = $this->orderedStoragePaths('character-classes/flat-icons', count($classes));

        return collect($classes)
            ->values()
            ->mapWithKeys(function (array $characterClass, int $index): array {
                $shorthand = strtolower((string) $characterClass['shorthand']);
                $iconSourceFilename = $this->sourceFilename((string) $characterClass['icon_source_url']);
                $flatIconSourceFilename = $this->sourceFilename((string) $characterClass['flaticon_source_url']);

                return [
                    (string) $characterClass['shorthand'] => [
                        'icon_url' => [
                            'reference_path' => (string) $characterClass['icon_reference_path'],
                            'seed_public_path' => (string) $characterClass['icon_public_path'],
                            'storage_source_path' => $iconStoragePaths[$index] ?? null,
                            'source_candidates' => $this->sourceCandidates([
                                $iconSourceFilename ? "character-classes/icons/{$iconSourceFilename}" : null,
                                "character-classes/icons/{$shorthand}.png",
                            ]),
                        ],
                        'flaticon_url' => [
                            'reference_path' => (string) $characterClass['flaticon_reference_path'],
                            'seed_public_path' => (string) $characterClass['flaticon_public_path'],
                            'storage_source_path' => $flatIconStoragePaths[$index] ?? null,
                            'source_candidates' => $this->sourceCandidates([
                                $flatIconSourceFilename ? "character-classes/flat-icons/{$flatIconSourceFilename}" : null,
                                $flatIconSourceFilename ? "character-classes/flaticons/{$flatIconSourceFilename}" : null,
                                "character-classes/flat-icons/{$shorthand}.png",
                                "character-classes/flaticons/{$shorthand}.png",
                            ]),
                        ],
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return array<string, array{icon_url: array{reference_path: string, seed_public_path: string, storage_source_path: string|null}, black_icon_url: array{reference_path: string, seed_public_path: string, storage_source_path: string|null}, transparent_icon_url: array{reference_path: string, seed_public_path: string, storage_source_path: string|null}, sprite_url: array{reference_path: string, seed_public_path: string, storage_source_path: string|null}}>
     */
    private function phantomJobPaths(): array
    {
        $phantomJobs = ReferenceIconCatalog::phantomJobs();
        $iconStoragePaths = $this->orderedStoragePaths('phantom-jobs/icons', count($phantomJobs));
        $blackIconStoragePaths = $this->orderedStoragePaths('phantom-jobs/black-icons', count($phantomJobs));
        $transparentIconStoragePaths = $this->orderedStoragePaths('phantom-jobs/transparent-icons', count($phantomJobs));
        $spriteStoragePaths = $this->orderedStoragePaths('phantom-jobs/sprites', count($phantomJobs));

        return collect($phantomJobs)
            ->values()
            ->mapWithKeys(fn (array $phantomJob, int $index): array => [
                (string) $phantomJob['name'] => [
                    'icon_url' => [
                        'reference_path' => (string) $phantomJob['icon_reference_path'],
                        'seed_public_path' => (string) $phantomJob['icon_public_path'],
                        'storage_source_path' => $iconStoragePaths[$index] ?? null,
                    ],
                    'black_icon_url' => [
                        'reference_path' => (string) $phantomJob['black_icon_reference_path'],
                        'seed_public_path' => (string) $phantomJob['black_icon_public_path'],
                        'storage_source_path' => $blackIconStoragePaths[$index] ?? null,
                    ],
                    'transparent_icon_url' => [
                        'reference_path' => (string) $phantomJob['transparent_icon_reference_path'],
                        'seed_public_path' => (string) $phantomJob['transparent_icon_public_path'],
                        'storage_source_path' => $transparentIconStoragePaths[$index] ?? null,
                    ],
                    'sprite_url' => [
                        'reference_path' => (string) $phantomJob['sprite_reference_path'],
                        'seed_public_path' => (string) $phantomJob['sprite_public_path'],
                        'storage_source_path' => $spriteStoragePaths[$index] ?? null,
                    ],
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function orderedStoragePaths(string $directory, int $expectedCount): array
    {
        $path = $this->sourceRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory);

        if (! is_dir($path)) {
            return [];
        }

        $files = collect(scandir($path) ?: [])
            ->filter(fn (string $file): bool => ! str_starts_with($file, '.') && is_file($path.DIRECTORY_SEPARATOR.$file))
            ->map(fn (string $file): array => [
                'file' => $file,
                'relative_path' => trim($directory, '/').'/'.$file,
                'modified_at' => filemtime($path.DIRECTORY_SEPARATOR.$file) ?: 0,
            ])
            ->sortBy([
                ['modified_at', 'asc'],
                ['file', 'asc'],
            ])
            ->values();

        if ($files->isNotEmpty() && $files->count() !== $expectedCount) {
            $this->warn(sprintf(
                'Expected %d files in %s, found %d. Files will still be matched by oldest first.',
                $expectedCount,
                $directory,
                $files->count(),
            ));
        }

        return $files
            ->pluck('relative_path')
            ->all();
    }

    private function sourceFilename(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $filename = basename(urldecode($path));

        return $filename !== '' ? $filename : null;
    }

    /**
     * @param  array<int, string|null>  $paths
     * @return array<int, string>
     */
    private function sourceCandidates(array $paths): array
    {
        return array_values(array_unique(array_filter(
            $paths,
            fn (?string $path): bool => is_string($path) && $path !== '',
        )));
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return rtrim($path, '\\/');
        }

        return rtrim(base_path($path), '\\/');
    }
}
