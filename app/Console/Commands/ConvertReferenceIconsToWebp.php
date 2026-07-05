<?php

namespace App\Console\Commands;

use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Support\SeedData\ReferenceIconCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ConvertReferenceIconsToWebp extends Command
{
    private const WEBP_QUALITY = 84;

    protected $signature = 'reference-icons:convert-webp
                            {--dry-run : Report conversions without writing files or updating records}
                            {--force : Recreate WebP files even when the destination already exists}
                            {--no-download : Do not download remote source URLs when local files are missing}
                            {--delete-originals : Delete source images after a successful conversion}';

    protected $description = 'Convert local character class and phantom job icon files to storage-backed WebP assets and update their database references.';

    /**
     * @var array{converted: int, updated: int, skipped: int, downloaded: int, missing: int, failed: int}
     */
    private array $totals = [
        'converted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'downloaded' => 0,
        'missing' => 0,
        'failed' => 0,
    ];

    public function handle(): int
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            $this->error('GD image processing with WebP support is not available.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $allowDownloads = ! (bool) $this->option('no-download');
        $deleteOriginals = (bool) $this->option('delete-originals');
        $characterClassPaths = $this->characterClassFallbackPaths();
        $phantomJobPaths = $this->phantomJobFallbackPaths();

        CharacterClass::query()
            ->orderBy('id')
            ->each(function (CharacterClass $characterClass) use ($dryRun, $force, $allowDownloads, $deleteOriginals, $characterClassPaths): void {
                $label = sprintf('Class %s', $characterClass->shorthand);
                $fallbackPaths = $characterClassPaths[$characterClass->shorthand] ?? [];

                $this->convertModelField($characterClass, 'icon_url', "{$label} icon", $dryRun, $force, $allowDownloads, $deleteOriginals, $fallbackPaths['icon_url'] ?? null);
                $this->convertModelField($characterClass, 'flaticon_url', "{$label} flat icon", $dryRun, $force, $allowDownloads, $deleteOriginals, $fallbackPaths['flaticon_url'] ?? null);
            });

        PhantomJob::query()
            ->orderBy('id')
            ->each(function (PhantomJob $phantomJob) use ($dryRun, $force, $allowDownloads, $deleteOriginals, $phantomJobPaths): void {
                $label = sprintf('Phantom job %s', $phantomJob->name);
                $fallbackPaths = $phantomJobPaths[$phantomJob->name] ?? [];

                $this->convertModelField($phantomJob, 'icon_url', "{$label} icon", $dryRun, $force, $allowDownloads, $deleteOriginals, $fallbackPaths['icon_url'] ?? null);
                $this->convertModelField($phantomJob, 'black_icon_url', "{$label} black icon", $dryRun, $force, $allowDownloads, $deleteOriginals, $fallbackPaths['black_icon_url'] ?? null);
                $this->convertModelField($phantomJob, 'transparent_icon_url', "{$label} transparent icon", $dryRun, $force, $allowDownloads, $deleteOriginals, $fallbackPaths['transparent_icon_url'] ?? null);
                $this->convertModelField($phantomJob, 'sprite_url', "{$label} sprite", $dryRun, $force, $allowDownloads, $deleteOriginals, $fallbackPaths['sprite_url'] ?? null);
            });

        $this->newLine();
        $this->info(sprintf('%s: %d', $dryRun ? 'Would convert' : 'Converted', $this->totals['converted']));
        $this->line(sprintf('%s: %d', $dryRun ? 'Would update references' : 'Updated references', $this->totals['updated']));
        $this->line(sprintf('Already WebP / blank / unchanged: %d', $this->totals['skipped']));
        $this->line(sprintf('%s remote images: %d', $dryRun ? 'Would download' : 'Downloaded', $this->totals['downloaded']));
        $this->line(sprintf('Missing local files: %d', $this->totals['missing']));
        $this->line(sprintf('Failed: %d', $this->totals['failed']));

        return $this->totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function convertModelField(
        Model $model,
        string $field,
        string $label,
        bool $dryRun,
        bool $force,
        bool $allowDownloads,
        bool $deleteOriginals,
        ?array $referencePaths = null,
    ): void {
        $url = $model->getAttribute($field);

        if (! is_string($url) || blank($url)) {
            $this->totals['skipped']++;

            return;
        }

        $targetStoragePath = $this->normalizeStoragePath($referencePaths['storage_path'] ?? null);

        if (! $targetStoragePath && strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) === 'webp') {
            $this->totals['skipped']++;

            return;
        }

        $sourcePath = $this->localPathFromUrl($url);
        $fallbackPublicPath = $referencePaths['public_path'] ?? null;
        $sourceUrl = $this->sourceUrl($url, $referencePaths['source_url'] ?? null);

        if ((! $sourcePath || ! is_file($sourcePath)) && is_string($fallbackPublicPath) && is_file(public_path($fallbackPublicPath))) {
            $sourcePath = public_path($fallbackPublicPath);
        }

        if ((! $sourcePath || ! is_file($sourcePath)) && ! $sourceUrl) {
            $this->totals['missing']++;
            $this->warn(sprintf('Missing %s (%s)', $label, $url));

            return;
        }

        $targetPath = $targetStoragePath
            ? storage_path('app/public/'.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetStoragePath))
            : $this->webpPathFor($sourcePath);
        $newUrl = $targetStoragePath
            ? Storage::disk('public')->url($targetStoragePath)
            : $this->publicUrlForPath($targetPath);

        $shouldConvert = ! is_file($targetPath) || $force;

        if ($shouldConvert && $dryRun) {
            $this->totals['converted']++;
            $this->totals['downloaded'] += $sourceUrl && (! $sourcePath || ! is_file($sourcePath)) ? 1 : 0;
            $this->line(sprintf('<info>Would convert</info> %s -> %s', $label, $newUrl));
        }

        if ($shouldConvert && ! $dryRun) {
            $binary = $this->imageBinary($sourcePath, $sourceUrl, $label, $allowDownloads);

            if (! $binary || ! $this->convertImage($binary, $targetPath, $label)) {
                return;
            }

            $this->totals['converted']++;
            $this->line(sprintf('<info>Converted</info> %s -> %s', $label, $newUrl));
        }

        if ($url !== $newUrl) {
            $this->totals['updated']++;

            if (! $dryRun) {
                $model->forceFill([$field => $newUrl])->save();
            }
        } else {
            $this->totals['skipped']++;
        }

        if ($deleteOriginals && ! $dryRun && is_file($targetPath) && is_string($sourcePath) && is_file($sourcePath) && $sourcePath !== $targetPath) {
            unlink($sourcePath);
        }
    }

    private function sourceUrl(string $currentUrl, mixed $catalogSourceUrl): ?string
    {
        if ($this->isRemoteUrl($currentUrl)) {
            return $currentUrl;
        }

        if (is_string($catalogSourceUrl) && $this->isRemoteUrl($catalogSourceUrl)) {
            return $catalogSourceUrl;
        }

        return null;
    }

    private function isRemoteUrl(string $url): bool
    {
        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && filled(parse_url($url, PHP_URL_HOST));
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

    private function normalizeStoragePath(mixed $path): ?string
    {
        if (! is_string($path) || blank($path)) {
            return null;
        }

        $normalized = trim(str_replace('\\', '/', $path), '/');

        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        return $normalized;
    }

    private function webpPathFor(string $sourcePath): string
    {
        $converted = preg_replace('/\.[^\.\\\\\/]+$/', '.webp', $sourcePath);

        return is_string($converted) && $converted !== $sourcePath
            ? $converted
            : $sourcePath.'.webp';
    }

    private function publicUrlForPath(string $absolutePath): string
    {
        $publicPath = rtrim(str_replace('\\', '/', public_path()), '/').'/';
        $normalizedPath = str_replace('\\', '/', $absolutePath);
        $relativePath = str_starts_with($normalizedPath, $publicPath)
            ? substr($normalizedPath, strlen($publicPath))
            : basename($normalizedPath);

        return '/'.ltrim($relativePath, '/');
    }

    private function imageBinary(?string $sourcePath, ?string $sourceUrl, string $label, bool $allowDownloads): ?string
    {
        if ($sourcePath && is_file($sourcePath)) {
            $binary = file_get_contents($sourcePath);

            if ($binary !== false) {
                return $binary;
            }

            $this->totals['failed']++;
            $this->error(sprintf('Unable to read %s', $sourcePath));

            return null;
        }

        if (! $sourceUrl) {
            return null;
        }

        if (! $allowDownloads) {
            $this->totals['missing']++;
            $this->warn(sprintf('Missing local file for %s; remote downloads are disabled.', $label));

            return null;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'FullParty reference icon importer (+https://fullparty.gg)',
            ])->timeout(20)->get($sourceUrl);
        } catch (\Throwable) {
            $this->totals['failed']++;
            $this->error(sprintf('Unable to download %s (%s)', $label, $sourceUrl));

            return null;
        }

        if (! $response->successful()) {
            $this->totals['failed']++;
            $this->error(sprintf('Unable to download %s (%s returned %d)', $label, $sourceUrl, $response->status()));

            return null;
        }

        $this->totals['downloaded']++;

        return $response->body();
    }

    private function convertImage(string $binary, string $targetPath, string $label): bool
    {
        if ($binary === '') {
            $this->totals['failed']++;
            $this->error(sprintf('Downloaded image for %s was empty.', $label));

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
     * @return array<string, array{icon_url: array{public_path: string, storage_path: string, source_url: string}, flaticon_url: array{public_path: string, storage_path: string, source_url: string}}>
     */
    private function characterClassFallbackPaths(): array
    {
        return collect(ReferenceIconCatalog::characterClasses())
            ->mapWithKeys(fn (array $characterClass): array => [
                (string) $characterClass['shorthand'] => [
                    'icon_url' => [
                        'public_path' => (string) $characterClass['icon_public_path'],
                        'storage_path' => (string) $characterClass['icon_storage_path'],
                        'source_url' => (string) $characterClass['icon_source_url'],
                    ],
                    'flaticon_url' => [
                        'public_path' => (string) $characterClass['flaticon_public_path'],
                        'storage_path' => (string) $characterClass['flaticon_storage_path'],
                        'source_url' => (string) $characterClass['flaticon_source_url'],
                    ],
                ],
            ])
            ->all();
    }

    /**
     * @return array<string, array{icon_url: array{public_path: string, storage_path: string, source_url: string}, black_icon_url: array{public_path: string, storage_path: string, source_url: string}, transparent_icon_url: array{public_path: string, storage_path: string, source_url: string}, sprite_url: array{public_path: string, storage_path: string, source_url: string}}>
     */
    private function phantomJobFallbackPaths(): array
    {
        return collect(ReferenceIconCatalog::phantomJobs())
            ->mapWithKeys(fn (array $phantomJob): array => [
                (string) $phantomJob['name'] => [
                    'icon_url' => [
                        'public_path' => (string) $phantomJob['icon_public_path'],
                        'storage_path' => (string) $phantomJob['icon_storage_path'],
                        'source_url' => (string) $phantomJob['icon_source_url'],
                    ],
                    'black_icon_url' => [
                        'public_path' => (string) $phantomJob['black_icon_public_path'],
                        'storage_path' => (string) $phantomJob['black_icon_storage_path'],
                        'source_url' => (string) $phantomJob['black_icon_source_url'],
                    ],
                    'transparent_icon_url' => [
                        'public_path' => (string) $phantomJob['transparent_icon_public_path'],
                        'storage_path' => (string) $phantomJob['transparent_icon_storage_path'],
                        'source_url' => (string) $phantomJob['transparent_icon_source_url'],
                    ],
                    'sprite_url' => [
                        'public_path' => (string) $phantomJob['sprite_public_path'],
                        'storage_path' => (string) $phantomJob['sprite_storage_path'],
                        'source_url' => (string) $phantomJob['sprite_source_url'],
                    ],
                ],
            ])
            ->all();
    }
}
