<?php

use App\Services\ManagedImageStorage;
use App\Support\Images\ImageDimensions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

it('rejects excessive image headers before GD raster decoding for every storage mode', function (int $width, int $height, bool $process) {
    Storage::fake('public');
    $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8foAAAAASUVORK5CYII=');
    $binary = substr_replace($binary, pack('NN', $width, $height), 16, 8);
    $binary = substr_replace($binary, pack('N', crc32(substr($binary, 12, 17))), 29, 4);
    $file = UploadedFile::fake()->createWithContent('large.png', $binary);

    try {
        app(ManagedImageStorage::class)->uploadImageIfPresent($file, 'test', $process);
        $this->fail('Excessive dimensions must be rejected before decoding the deliberately incomplete raster.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            $process ? 'profile_picture' : 'image' => [ImageDimensions::errorMessage()],
        ]);
    }
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([[8193, 1, false], [1, 8193, true], [6000, 6000, false], [6000, 6000, true]]);

it('localizes image dimension errors in every supported language', function (string $locale) {
    app()->setLocale($locale);
    expect(ImageDimensions::errorMessage())->not->toContain('errors.image_dimensions_exceeded')
        ->toContain('8192')->toContain('25');
})->with(['en', 'de', 'fr', 'ja']);
