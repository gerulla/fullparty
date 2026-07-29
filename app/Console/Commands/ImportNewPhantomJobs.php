<?php

namespace App\Console\Commands;

use App\Models\PhantomJob;
use App\Support\SeedData\ReferenceIconCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;

class ImportNewPhantomJobs extends Command
{
    private const WEBP_QUALITY = 84;

    private const ASSETS = [
        'blue' => ['field' => 'icon_url', 'directory' => 'icons'],
        'black' => ['field' => 'black_icon_url', 'directory' => 'black-icons'],
        'transparent' => ['field' => 'transparent_icon_url', 'directory' => 'transparent-icons'],
        'sprite' => ['field' => 'sprite_url', 'directory' => 'sprites'],
    ];

    protected $signature = 'phantom-jobs:import-new
                            {--source-root=public/newjobs : Directory containing one sources.json bundle per job}
                            {--target-root=public/reference-icons/phantom-jobs : Git-tracked WebP destination}
                            {--dry-run : Validate and report without writing files or updating records}
                            {--force : Recreate WebP files that already exist}';

    protected $description = 'Import locally prepared Phantom Job bundles, convert their icons to WebP, and upsert their records.';

    public function handle(): int
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            $this->error('GD image processing with WebP support is not available.');

            return self::FAILURE;
        }

        $sourceRoot = $this->resolvePath((string) $this->option('source-root'));
        $targetRoot = $this->resolvePath((string) $this->option('target-root'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $bundles = $this->loadBundles($sourceRoot, $targetRoot);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $converted = 0;
        $skipped = 0;

        foreach ($bundles as $bundle) {
            foreach ($bundle['assets'] as $asset) {
                if (is_file($asset['target_path']) && ! $force) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $converted++;

                    continue;
                }

                if (! $this->convertToWebp($asset['source_path'], $asset['target_path'])) {
                    $this->error(sprintf('Unable to convert %s for %s.', $asset['key'], $bundle['name']));

                    return self::FAILURE;
                }

                $converted++;
            }
        }

        if (! $dryRun) {
            DB::transaction(function () use ($bundles): void {
                foreach ($bundles as $bundle) {
                    PhantomJob::query()->updateOrCreate(
                        ['name' => $bundle['name']],
                        [
                            'max_level' => $bundle['max_level'],
                            ...collect($bundle['assets'])
                                ->mapWithKeys(fn (array $asset): array => [$asset['field'] => $asset['url']])
                                ->all(),
                        ],
                    );
                }

                $freelancer = collect(ReferenceIconCatalog::phantomJobs())
                    ->firstWhere('name', 'Phantom Freelancer');

                if ($freelancer) {
                    PhantomJob::query()
                        ->where('name', 'Phantom Freelancer')
                        ->update(['max_level' => $freelancer['max_level']]);
                }
            });
        }

        $this->newLine();
        $this->info(sprintf('%s %d Phantom Jobs.', $dryRun ? 'Validated' : 'Imported', count($bundles)));
        $this->line(sprintf('%s WebP files: %d', $dryRun ? 'Would create' : 'Created', $converted));
        $this->line(sprintf('Existing WebP files skipped: %d', $skipped));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     max_level: int,
     *     assets: array<int, array{key: string, field: string, source_path: string, target_path: string, url: string}>
     * }>
     */
    private function loadBundles(string $sourceRoot, string $targetRoot): array
    {
        if (! is_dir($sourceRoot)) {
            throw new \RuntimeException("Phantom Job source directory not found: {$sourceRoot}");
        }

        $catalog = collect(ReferenceIconCatalog::phantomJobs())->keyBy('name');
        $manifests = File::glob($sourceRoot.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'sources.json') ?: [];

        if ($manifests === []) {
            throw new \RuntimeException("No Phantom Job manifests found under {$sourceRoot}");
        }

        $bundles = [];
        $seenNames = [];

        foreach ($manifests as $manifestPath) {
            try {
                $manifest = json_decode(
                    File::get($manifestPath),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                throw new \RuntimeException("Invalid JSON in {$manifestPath}: {$exception->getMessage()}", previous: $exception);
            }

            $name = trim((string) ($manifest['name'] ?? ''));
            $slug = Str::slug($name);
            $sourceDirectory = dirname($manifestPath);

            if ($name === '' || $slug === '') {
                throw new \RuntimeException("Manifest {$manifestPath} does not contain a valid job name.");
            }

            if (basename($sourceDirectory) !== $slug) {
                throw new \RuntimeException("Manifest directory for {$name} must be named {$slug}.");
            }

            if (isset($seenNames[$name])) {
                throw new \RuntimeException("Duplicate Phantom Job manifest for {$name}.");
            }

            $catalogEntry = $catalog->get($name);

            if (! is_array($catalogEntry)) {
                throw new \RuntimeException("No max-level catalog entry exists for {$name}.");
            }

            $assets = [];

            foreach (self::ASSETS as $key => $definition) {
                $filename = $manifest['assets'][$key]['file'] ?? null;

                if (! is_string($filename) || $filename === '' || basename($filename) !== $filename) {
                    throw new \RuntimeException("Manifest for {$name} has an invalid {$key} asset filename.");
                }

                $sourcePath = $sourceDirectory.DIRECTORY_SEPARATOR.$filename;

                if (! is_file($sourcePath)) {
                    throw new \RuntimeException("Missing {$key} asset for {$name}: {$sourcePath}");
                }

                $imageInfo = @getimagesize($sourcePath);

                if (($imageInfo['mime'] ?? null) !== 'image/png') {
                    throw new \RuntimeException("The {$key} asset for {$name} is not a valid PNG.");
                }

                $relativeTarget = $definition['directory'].'/'.$slug.'.webp';
                $assets[] = [
                    'key' => $key,
                    'field' => $definition['field'],
                    'source_path' => $sourcePath,
                    'target_path' => rtrim($targetRoot, '\\/')
                        .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeTarget),
                    'url' => '/reference-icons/phantom-jobs/'.$relativeTarget,
                ];
            }

            $seenNames[$name] = true;
            $bundles[] = [
                'name' => $name,
                'max_level' => (int) $catalogEntry['max_level'],
                'assets' => $assets,
            ];
        }

        return $bundles;
    }

    private function convertToWebp(string $sourcePath, string $targetPath): bool
    {
        $binary = File::get($sourcePath);
        $image = @imagecreatefromstring($binary);

        if (! $image) {
            return false;
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);
        File::ensureDirectoryExists(dirname($targetPath));

        $converted = imagewebp($image, $targetPath, self::WEBP_QUALITY);
        imagedestroy($image);

        return $converted;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
