<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagedImageStorage
{
    private const GROUP_IMAGE_SIZE = 800;

    private const GROUP_IMAGE_JPEG_QUALITY = 82;

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

    public function uploadImageIfPresent(?UploadedFile $file, string $directory, bool $shouldProcess = false): ?string
    {
        if (!$file) {
            return null;
        }

        $path = $shouldProcess
            ? $this->storeProcessedUploadedFile($file, $directory)
            : $this->storeUploadedFile($file, $directory);

        return Storage::disk('public')->url($path);
    }

    public function replaceUploadedImageIfPresent(?string $currentUrl, ?UploadedFile $file, string $directory, bool $shouldProcess = false): ?string
    {
        if (!$file) {
            return $currentUrl;
        }

        $uploadedUrl = $this->uploadImageIfPresent($file, $directory, $shouldProcess);

        $this->deleteManagedImage($currentUrl, $directory);

        return $uploadedUrl;
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

    private function storeUploadedFile(UploadedFile $file, string $directory): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    private function storeProcessedUploadedFile(UploadedFile $file, string $directory): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages([
                'profile_picture' => 'Image processing is not available on this server.',
            ]);
        }

        $binary = file_get_contents($file->getRealPath());

        if ($binary === false) {
            throw ValidationException::withMessages([
                'profile_picture' => 'Unable to read the uploaded image.',
            ]);
        }

        $source = @imagecreatefromstring($binary);

        if (!$source) {
            throw ValidationException::withMessages([
                'profile_picture' => 'The uploaded file must be a valid image.',
            ]);
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $squareSize = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $squareSize) / 2);
        $sourceY = (int) floor(($sourceHeight - $squareSize) / 2);

        $canvas = imagecreatetruecolor(self::GROUP_IMAGE_SIZE, self::GROUP_IMAGE_SIZE);

        if (!$canvas) {
            imagedestroy($source);

            throw ValidationException::withMessages([
                'profile_picture' => 'Unable to process the uploaded image.',
            ]);
        }

        $background = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $background);

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            self::GROUP_IMAGE_SIZE,
            self::GROUP_IMAGE_SIZE,
            $squareSize,
            $squareSize
        );

        imagedestroy($source);

        $path = trim($directory, '/').'/'.Str::uuid().'.jpg';
        $temporaryFile = tempnam(sys_get_temp_dir(), 'group-image-');

        if ($temporaryFile === false || !imagejpeg($canvas, $temporaryFile, self::GROUP_IMAGE_JPEG_QUALITY)) {
            imagedestroy($canvas);

            throw ValidationException::withMessages([
                'profile_picture' => 'Unable to save the processed image.',
            ]);
        }

        imagedestroy($canvas);

        Storage::disk('public')->put($path, file_get_contents($temporaryFile));
        @unlink($temporaryFile);

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
