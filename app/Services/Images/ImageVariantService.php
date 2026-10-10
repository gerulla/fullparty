<?php

namespace App\Services\Images;

use App\DTOs\Images\ImageTransformOptions;
use App\Support\Images\ImageDimensions;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageVariantService
{
    public function url(?string $sourceUrl, ImageTransformOptions $options): ?string
    {
        if (! $sourceUrl) {
            return null;
        }

        $source = parse_url($sourceUrl);
        $base = parse_url(Storage::disk('public')->url(''));
        if (! is_array($source) || ! is_array($base) || isset($source['user']) || isset($source['pass'])) {
            return null;
        }
        if (isset($source['scheme']) && ! isset($source['host'])) {
            return null;
        }
        if (isset($source['host']) && (
            ! in_array($source['scheme'] ?? '', ['http', 'https'], true)
            || strcasecmp($source['host'], $base['host'] ?? '') !== 0
            || ($source['port'] ?? null) !== ($base['port'] ?? null)
        )) {
            return null;
        }
        $prefix = rtrim($base['path'] ?? '/storage', '/').'/';
        $urlPath = rawurldecode($source['path'] ?? '');
        if (! str_starts_with($urlPath, $prefix)) {
            return null;
        }
        $path = substr($urlPath, strlen($prefix));
        if (! $this->validPath($path)) {
            return null;
        }

        // Use the configured origin, including in jobs or requests on another host.
        return rtrim(config('app.url'), '/').route('images.transform', ['path' => $path, ...$options->query()], false);
    }

    public function variant(string $path, ImageTransformOptions $options, string $clientIp): string
    {
        $source = $this->sourcePath($path);
        abort_if(filesize($source) > config('image_variants.max_source_bytes'), 413);
        $info = @getimagesize($source);
        abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true), 415);
        abort_unless(ImageDimensions::allowed($info[0], $info[1]), 413);
        $geometry = $this->geometry($info[0], $info[1], $options);

        $sourceHash = hash_file('sha256', $source);
        abort_if($sourceHash === false, 404);
        $key = hash('sha256', json_encode([$sourceHash, $options->query(), config('image_variants.quality'), 1], JSON_THROW_ON_ERROR));
        $directory = config('image_variants.cache_directory').'/'.hash('sha256', $path);
        $variant = $directory.'/'.$key.'.webp';
        $disk = Storage::disk(config('image_variants.cache_disk'));
        if ($disk->exists($variant)) {
            return $disk->path($variant);
        }

        try {
            // One generator per source also makes the per-image cache bound atomic.
            return Cache::lock('image-variant:'.hash('sha256', $path), 30)->block(3, function () use ($source, $geometry, $clientIp, $directory, $variant, $disk) {
                if ($disk->exists($variant)) {
                    return $disk->path($variant);
                }
                $this->limitGeneration($clientIp);
                $binary = $this->render($source, $geometry);
                $files = collect($disk->files($directory))->sortBy(fn ($file) => $disk->lastModified($file))->values();
                $removeCount = max(0, $files->count() - (int) config('image_variants.max_variants_per_image') + 1);
                foreach ($files->take($removeCount) as $file) {
                    $disk->delete($file);
                }

                $temporary = $variant.'.'.Str::uuid().'.tmp';
                try {
                    abort_unless($disk->put($temporary, $binary) && $disk->move($temporary, $variant), 503);
                } finally {
                    $disk->delete($temporary);
                }

                return $disk->path($variant);
            });
        } catch (LockTimeoutException) {
            abort(503, '', ['Retry-After' => '3']);
        }
    }

    public function prune(): int
    {
        $disk = Storage::disk(config('image_variants.cache_disk'));
        $files = collect($disk->allFiles(config('image_variants.cache_directory')))
            ->filter(fn ($file) => str_ends_with($file, '.webp'))
            ->map(fn ($file) => ['path' => $file, 'time' => $disk->lastModified($file), 'size' => $disk->size($file)])
            ->sortBy('time');
        $bytes = $files->sum('size');
        $cutoff = now()->subDays((int) config('image_variants.cache_days'))->timestamp;
        $removed = 0;
        foreach ($files as $file) {
            if ($file['time'] < $cutoff || $bytes > config('image_variants.cache_max_bytes')) {
                if ($disk->delete($file['path'])) {
                    $bytes -= $file['size'];
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function validPath(string $path): bool
    {
        $segments = explode('/', $path);

        return in_array($segments[0], config('image_variants.source_directories'), true)
            && ! array_intersect($segments, ['', '.', '..'])
            && preg_match('/\A[a-zA-Z0-9_\/.\-]+\.(?:jpe?g|png|webp)\z/i', $path) === 1;
    }

    private function sourcePath(string $path): string
    {
        abort_unless($this->validPath($path), 404);
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $source = realpath($disk->path($path));
        abort_unless($root && $source && is_file($source)
            && str_starts_with(str_replace('\\', '/', $source), rtrim(str_replace('\\', '/', $root), '/').'/'), 404);

        return $source;
    }

    private function limitGeneration(string $ip): void
    {
        foreach (['global' => (int) config('image_variants.generations_per_minute'), 'ip:'.$ip => (int) config('image_variants.generations_per_ip_per_minute')] as $key => $limit) {
            $key = 'image-generation:'.$key;
            abort_if(RateLimiter::tooManyAttempts($key, $limit), 429, '', ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
            RateLimiter::hit($key, 60);
        }
    }

    /** @return array{int, int, int, int, int, int} */
    private function geometry(int $width, int $height, ImageTransformOptions $options): array
    {
        [$x, $y, $sourceWidth, $sourceHeight] = $options->crop ?? [0, 0, $width, $height];
        abort_if($x < 0 || $y < 0 || $sourceWidth < 1 || $sourceHeight < 1 || $x + $sourceWidth > $width || $y + $sourceHeight > $height, 422);
        if ($options->fit === 'crop') {
            $ratio = $options->width / $options->height;
            $cropWidth = min($sourceWidth, (int) round($sourceHeight * $ratio));
            $cropHeight = min($sourceHeight, (int) round($sourceWidth / $ratio));
            $x += match ($options->position) {
                'left' => 0, 'right' => $sourceWidth - $cropWidth,
                default => (int) floor(($sourceWidth - $cropWidth) / 2),
            };
            $y += match ($options->position) {
                'top' => 0, 'bottom' => $sourceHeight - $cropHeight,
                default => (int) floor(($sourceHeight - $cropHeight) / 2),
            };
            $sourceWidth = max(1, $cropWidth);
            $sourceHeight = max(1, $cropHeight);
        }
        $scale = min($options->width ? $options->width / $sourceWidth : INF, $options->height ? $options->height / $sourceHeight : INF);
        if (! $options->upscale) {
            $scale = min(1, $scale);
        }
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        if ($options->fit === 'crop' && ($options->upscale || ($options->width <= $sourceWidth && $options->height <= $sourceHeight))) {
            [$targetWidth, $targetHeight] = [$options->width, $options->height];
        }
        // A single requested dimension can imply an enormous other dimension for a very wide/tall source.
        abort_if($targetWidth > config('image_variants.max_dimension') || $targetHeight > config('image_variants.max_dimension'), 422);

        return [$x, $y, $sourceWidth, $sourceHeight, $targetWidth, $targetHeight];
    }

    /** @param array{int, int, int, int, int, int} $geometry */
    private function render(string $path, array $geometry): string
    {
        abort_unless(function_exists('imagecreatefromstring') && function_exists('imagewebp'), 503);
        $source = @imagecreatefromstring(file_get_contents($path));
        abort_unless($source, 415);
        [$x, $y, $width, $height, $targetWidth, $targetHeight] = $geometry;
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        abort_unless($canvas, 503);
        try {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            abort_unless(imagecopyresampled($canvas, $source, 0, 0, $x, $y, $targetWidth, $targetHeight, $width, $height), 503);
            ob_start();
            try {
                $success = imagewebp($canvas, null, (int) config('image_variants.quality'));
                $binary = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            abort_unless($success && is_string($binary) && $binary !== '', 503);

            return $binary;
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }
    }
}
