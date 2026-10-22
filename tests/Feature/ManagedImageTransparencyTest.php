<?php

use App\Services\ManagedImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('preserves full and partial transparency when converting and resizing images to WebP', function (string $mode, string $format) {
    Storage::fake('public');
    Storage::fake('local');

    $source = imagecreatetruecolor(3000, 1500);
    imagealphablending($source, false);
    imagesavealpha($source, true);

    // Horizontal bands survive both proportional resizing and the centered square crop.
    foreach ([127, 64, 0] as $index => $alpha) {
        imagefilledrectangle(
            $source, 0, $index * 500, 2999, ($index + 1) * 500 - 1,
            imagecolorallocatealpha($source, 150, 80, 210, $alpha),
        );
    }

    ob_start();
    match ($format) {
        'png' => imagepng($source),
        'webp' => imagewebp($source, null, 100),
    };
    $binary = ob_get_clean();
    imagedestroy($source);

    $storage = app(ManagedImageStorage::class);
    if ($mode === 'local') {
        Storage::disk('local')->put('source.'.$format, $binary);
        $url = $storage->storeLocalImageAsWebp(
            Storage::disk('local')->path('source.'.$format),
            'converted/image.webp',
        );
    } else {
        $url = $storage->uploadImageIfPresent(
            UploadedFile::fake()->createWithContent('image.'.$format, $binary),
            'converted',
            shouldProcess: $mode === 'profile',
        );
    }

    $paths = Storage::disk('public')->allFiles('converted');
    expect($paths)->toHaveCount(1)
        ->and($url)->toBe(Storage::disk('public')->url($paths[0]))
        ->toEndWith('.webp');

    $stored = Storage::disk('public')->get($paths[0]);
    expect(getimagesizefromstring($stored)['mime'])->toBe('image/webp');

    $result = imagecreatefromstring($stored);
    $width = imagesx($result);
    $height = imagesy($result);
    expect([$width, $height])->toBe($mode === 'profile' ? [800, 800] : [2400, 1200]);

    foreach ([127, 64, 0] as $index => $alpha) {
        $pixel = imagecolorsforindex($result, imagecolorat($result, (int) ($width / 2), (int) ($height * ($index + 0.5) / 3)));
        expect($pixel['alpha'])->toBe($alpha);
    }

    imagedestroy($result);
})->with(['upload', 'profile', 'local'])->with(['png', 'webp']);
