<?php

namespace App\DTOs\Images;

final readonly class ImageTransformOptions
{
    /** @param array{int, int, int, int}|null $crop */
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public string $fit = 'contain',
        public string $position = 'center',
        public ?array $crop = null,
        public bool $upscale = false,
    ) {}

    /** @return array<string, int|string> */
    public function query(): array
    {
        return array_filter([
            'width' => $this->width,
            'height' => $this->height,
            'fit' => $this->fit,
            'position' => $this->fit === 'crop' ? $this->position : null,
            'crop' => $this->crop ? implode(',', $this->crop) : null,
            'upscale' => $this->upscale ? 1 : null,
        ], fn ($value) => $value !== null);
    }
}
