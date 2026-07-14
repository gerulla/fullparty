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

    private const MAX_UPLOADED_IMAGE_DIMENSION = 2400;

    private const WEBP_QUALITY = 84;

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
        if (! $file) {
            return null;
        }

        $path = $shouldProcess
            ? $this->storeProcessedUploadedFile($file, $directory)
            : $this->storeUploadedFile($file, $directory);

        return Storage::disk('public')->url($path);
    }

    public function replaceUploadedImageIfPresent(?string $currentUrl, ?UploadedFile $file, string $directory, bool $shouldProcess = false): ?string
    {
        if (! $file) {
            return $currentUrl;
        }

        $uploadedUrl = $this->uploadImageIfPresent($file, $directory, $shouldProcess);

        $this->deleteManagedImage($currentUrl, $directory);

        return $uploadedUrl;
    }

    public function downloadImageToPath(string $url, string $field, string $absolutePath): void
    {
        $response = $this->fetchImageResponse($url, $field);
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages([
                $field => 'Unable to create the destination directory for the downloaded image.',
            ]);
        }

        if (file_put_contents($absolutePath, $response->body()) === false) {
            throw ValidationException::withMessages([
                $field => 'Unable to save the downloaded image.',
            ]);
        }
    }

    public function storeLocalImageAsWebp(string $absolutePath, string $storagePath): string
    {
        $binary = is_file($absolutePath) ? file_get_contents($absolutePath) : false;

        if ($binary === false) {
            throw ValidationException::withMessages([
                'image' => 'Unable to read the local image.',
            ]);
        }

        $source = $this->decodeImage($binary, 'image');
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        [$targetWidth, $targetHeight] = $this->constrainedDimensions($sourceWidth, $sourceHeight);
        $canvas = $this->createResampledCanvas(
            source: $source,
            sourceX: 0,
            sourceY: 0,
            sourceWidth: $sourceWidth,
            sourceHeight: $sourceHeight,
            targetWidth: $targetWidth,
            targetHeight: $targetHeight,
            preserveTransparency: true,
            field: 'image',
        );

        imagedestroy($source);

        Storage::disk('public')->put($storagePath, $this->encodeWebp($canvas, 'image'));
        imagedestroy($canvas);

        return Storage::disk('public')->url($storagePath);
    }

    public function copyManagedImage(?string $url, string $directory): ?string
    {
        if (blank($url)) {
            return null;
        }

        $sourcePath = $this->storagePathFromUrl($url, $directory);

        if (! $sourcePath || ! Storage::disk('public')->exists($sourcePath)) {
            return $url;
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'png';
        $copyPath = trim($directory, '/').'/'.Str::uuid().'.'.strtolower($extension);

        Storage::disk('public')->copy($sourcePath, $copyPath);

        return Storage::disk('public')->url($copyPath);
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

        if (! $this->extensionFromMimeType($contentType)) {
            throw ValidationException::withMessages([
                $field => 'The image must be a JPG, PNG, GIF, or WEBP file.',
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
        $source = $this->decodeUploadedImage($file, 'image');
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        [$targetWidth, $targetHeight] = $this->constrainedDimensions($sourceWidth, $sourceHeight);
        $canvas = $this->createResampledCanvas(
            source: $source,
            sourceX: 0,
            sourceY: 0,
            sourceWidth: $sourceWidth,
            sourceHeight: $sourceHeight,
            targetWidth: $targetWidth,
            targetHeight: $targetHeight,
            preserveTransparency: true,
            field: 'image',
        );

        imagedestroy($source);

        $path = trim($directory, '/').'/'.Str::uuid().'.webp';
        Storage::disk('public')->put($path, $this->encodeWebp($canvas, 'image'));
        imagedestroy($canvas);

        return $path;
    }

    private function storeProcessedUploadedFile(UploadedFile $file, string $directory): string
    {
        $source = $this->decodeUploadedImage($file, 'profile_picture');
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $squareSize = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $squareSize) / 2);
        $sourceY = (int) floor(($sourceHeight - $squareSize) / 2);
        $canvas = $this->createResampledCanvas(
            source: $source,
            sourceX: $sourceX,
            sourceY: $sourceY,
            sourceWidth: $squareSize,
            sourceHeight: $squareSize,
            targetWidth: self::GROUP_IMAGE_SIZE,
            targetHeight: self::GROUP_IMAGE_SIZE,
            preserveTransparency: false,
            field: 'profile_picture',
        );

        imagedestroy($source);

        $path = trim($directory, '/').'/'.Str::uuid().'.webp';
        Storage::disk('public')->put($path, $this->encodeWebp($canvas, 'profile_picture'));
        imagedestroy($canvas);

        return $path;
    }

    private function decodeUploadedImage(UploadedFile $file, string $field): \GdImage
    {
        $binary = file_get_contents($file->getRealPath());

        if ($binary === false) {
            throw ValidationException::withMessages([
                $field => 'Unable to read the uploaded image.',
            ]);
        }

        $source = $this->decodeImage($binary, $field);

        return $this->normalizeImageOrientation($source, $file);
    }

    private function decodeImage(string $binary, string $field): \GdImage
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages([
                $field => 'Image processing is not available on this server.',
            ]);
        }

        $source = @imagecreatefromstring($binary);

        if (! $source) {
            throw ValidationException::withMessages([
                $field => 'The file must be a valid image.',
            ]);
        }

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($source);
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);

        return $source;
    }

    private function normalizeImageOrientation(\GdImage $source, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! function_exists('imagerotate')) {
            return $source;
        }

        $mimeType = $this->mimeTypeFromImageContents($file);

        if (! in_array($mimeType, ['image/jpeg', 'image/jpg'], true)) {
            return $source;
        }

        $exif = @exif_read_data($file->getRealPath());
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($source, 180, 0),
            6 => imagerotate($source, -90, 0),
            8 => imagerotate($source, 90, 0),
            default => false,
        };

        if (! $rotated) {
            return $source;
        }

        imagedestroy($source);

        return $rotated;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function constrainedDimensions(int $sourceWidth, int $sourceHeight): array
    {
        $largestDimension = max($sourceWidth, $sourceHeight);

        if ($largestDimension <= self::MAX_UPLOADED_IMAGE_DIMENSION) {
            return [$sourceWidth, $sourceHeight];
        }

        $scale = self::MAX_UPLOADED_IMAGE_DIMENSION / $largestDimension;

        return [
            max(1, (int) round($sourceWidth * $scale)),
            max(1, (int) round($sourceHeight * $scale)),
        ];
    }

    private function createResampledCanvas(
        \GdImage $source,
        int $sourceX,
        int $sourceY,
        int $sourceWidth,
        int $sourceHeight,
        int $targetWidth,
        int $targetHeight,
        bool $preserveTransparency,
        string $field,
    ): \GdImage {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $canvas) {
            throw ValidationException::withMessages([
                $field => 'Unable to process the uploaded image.',
            ]);
        }

        if ($preserveTransparency) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $background = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        } else {
            $background = imagecolorallocate($canvas, 255, 255, 255);
        }

        if ($background === false) {
            imagedestroy($canvas);

            throw ValidationException::withMessages([
                $field => 'Unable to process the uploaded image.',
            ]);
        }

        imagefill($canvas, 0, 0, $background);

        $copied = imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        if (! $copied) {
            imagedestroy($canvas);

            throw ValidationException::withMessages([
                $field => 'Unable to process the uploaded image.',
            ]);
        }

        return $canvas;
    }

    private function encodeWebp(\GdImage $image, string $field): string
    {
        ob_start();
        $result = imagewebp($image, null, self::WEBP_QUALITY);
        $binary = ob_get_clean();

        if (! $result || ! is_string($binary) || $binary === '') {
            throw ValidationException::withMessages([
                $field => 'Unable to save the processed image.',
            ]);
        }

        return $binary;
    }

    private function resolveImageExtension(Response $response, string $sourceUrl): string
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        $extension = $this->extensionFromMimeType($contentType)
            ?? $this->sanitizeImageExtension(pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION))
            ?? 'png';

        return $extension;
    }

    private function mimeTypeFromImageContents(UploadedFile $file): ?string
    {
        $imageInfo = @getimagesize($file->getRealPath());

        if (! is_array($imageInfo) || ! isset($imageInfo['mime'])) {
            return null;
        }

        return is_string($imageInfo['mime']) ? $imageInfo['mime'] : null;
    }

    private function extensionFromMimeType(string $mimeType): ?string
    {
        $normalized = strtolower(trim(explode(';', $mimeType, 2)[0]));

        return match ($normalized) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    private function sanitizeImageExtension(?string $extension): ?string
    {
        $normalized = strtolower(trim((string) $extension));

        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            default => null,
        };
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
