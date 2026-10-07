<?php

namespace App\Support\Images;

final class ImageDimensions
{
    public const MAX_DIMENSION = 8192;

    public const MAX_PIXELS = 25_000_000;

    public static function allowed(int $width, int $height): bool
    {
        return $width > 0 && $height > 0
            && $width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION
            && $width * $height <= self::MAX_PIXELS;
    }

    public static function errorMessage(): string
    {
        return __('errors.image_dimensions_exceeded', [
            'dimension' => self::MAX_DIMENSION,
            'megapixels' => self::MAX_PIXELS / 1_000_000,
        ]);
    }
}
