<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagedImageStorage
{
    public function downloadImageIfPresent(?string $url, string $field, string $directory): ?string
    {
        if (blank($url)) {
            return null;
        }

        $response = $this->fetchImageResponse($url, $field);
        $path = $this->storeImageResponse($response, $url, $directory);

        return Storage::disk('public')->url($path);
    }

    public function replaceImageIfPresent(?string $currentUrl, ?string $newUrl, string $field, string $directory): ?string
    {
        if (blank($newUrl)) {
            $this->deleteManagedImage($currentUrl, $directory);

            return null;
        }

        if ($newUrl === $currentUrl) {
            return $currentUrl;
        }

        $downloadedUrl = $this->downloadImageIfPresent($newUrl, $field, $directory);

        $this->deleteManagedImage($currentUrl, $directory);

        return $downloadedUrl;
    }

    public function deleteManagedImage(?string $url, string $directory): void
    {
        if (blank($url)) {
            return;
        }

        $path = $this->storagePathFromUrl($url, $directory);

        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function fetchImageResponse(string $url, string $field): Response
    {
        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                $field => 'Unable to download image from the provided URL.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                $field => 'Unable to download image from the provided URL.',
            ]);
        }

        $contentType = (string) $response->header('Content-Type');

        if (! str_starts_with(strtolower($contentType), 'image/')) {
            throw ValidationException::withMessages([
                $field => 'The provided URL must point to an image.',
            ]);
        }

        return $response;
    }

    private function storeImageResponse(Response $response, string $sourceUrl, string $directory): string
    {
        $extension = $this->resolveImageExtension($response, $sourceUrl);
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    private function resolveImageExtension(Response $response, string $sourceUrl): string
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        $extension = match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION),
        };

        return filled($extension) ? strtolower($extension) : 'png';
    }

    private function storagePathFromUrl(string $url, string $directory): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $storagePrefix = '/storage/'.trim($directory, '/').'/';

        if (! str_starts_with($path, $storagePrefix)) {
            return null;
        }

        return Str::after($path, '/storage/');
    }
}
