<?php

use App\DTOs\Images\ImageTransformOptions;
use App\Services\Images\ImageVariantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
});

function storeVariantTestImage(string $path = 'activity-types/banner.png', int $width = 600, int $height = 600): void
{
    $image = imagecreatetruecolor($width, $height);
    foreach ([[255, 0, 0], [0, 255, 0], [0, 0, 255]] as $index => [$red, $green, $blue]) {
        imagefilledrectangle($image, 0, (int) ($height * $index / 3), $width - 1, (int) ($height * ($index + 1) / 3) - 1, imagecolorallocate($image, $red, $green, $blue));
    }
    ob_start();
    imagepng($image);
    Storage::disk('public')->put($path, ob_get_clean());
    imagedestroy($image);
}

function variantTestUrl(array $options = []): string
{
    return route('images.transform', array_merge(['path' => 'activity-types/banner.png', 'width' => 300], $options));
}

it('serves proportional WebP variants without a session or altering the original', function () {
    storeVariantTestImage(width: 600, height: 300);
    $before = Storage::disk('public')->get('activity-types/banner.png');
    $response = $this->get(variantTestUrl())->assertOk()->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderMissing('Set-Cookie');
    $dimensions = getimagesize($response->baseResponse->getFile()->getPathname());
    expect([$dimensions[0], $dimensions[1]])->toBe([300, 150])
        ->and($response->headers->get('Cache-Control'))->toContain('public', 'max-age=3600')
        ->and(Storage::disk('public')->get('activity-types/banner.png'))->toBe($before);
    Http::assertNothingSent();
});

it('supports height-only sizing and never upscales by default', function () {
    storeVariantTestImage(width: 600, height: 300);
    $response = $this->get(route('images.transform', ['path' => 'activity-types/banner.png', 'height' => 50]))->assertOk();
    expect(array_slice(getimagesize($response->baseResponse->getFile()->getPathname()), 0, 2))->toBe([100, 50]);
    $response = $this->get(variantTestUrl(['width' => 1200]))->assertOk();
    expect(array_slice(getimagesize($response->baseResponse->getFile()->getPathname()), 0, 2))->toBe([600, 300]);
});

it('fits inside both dimensions without stretching and preserves transparency', function () {
    $image = imagecreatetruecolor(400, 200);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 100, 50, 300, 150, imagecolorallocate($image, 255, 0, 0));
    ob_start();
    imagepng($image);
    Storage::disk('public')->put('activity-types/banner.png', ob_get_clean());
    imagedestroy($image);
    $response = $this->get(variantTestUrl(['height' => 100]))->assertOk();
    $result = imagecreatefromwebp($response->baseResponse->getFile()->getPathname());
    expect([imagesx($result), imagesy($result)])->toBe([200, 100])
        ->and(imagecolorsforindex($result, imagecolorat($result, 0, 0))['alpha'])->toBe(127);
    imagedestroy($result);
});

it('fills the exact banner dimensions and selects the requested crop position', function (string $position, string $channel) {
    storeVariantTestImage();
    $response = $this->get(variantTestUrl(['width' => 1200, 'height' => 400, 'fit' => 'crop', 'position' => $position, 'upscale' => 1]))->assertOk();
    $image = imagecreatefromwebp($response->baseResponse->getFile()->getPathname());
    $color = imagecolorsforindex($image, imagecolorat($image, 600, 200));
    expect([imagesx($image), imagesy($image)])->toBe([1200, 400])->and($color[$channel])->toBeGreaterThan(240);
    imagedestroy($image);
})->with([['center', 'green'], ['top', 'red'], ['bottom', 'blue']]);

it('crops an explicit rectangle before resizing', function () {
    storeVariantTestImage();
    $response = $this->get(variantTestUrl(['crop' => '0,400,600,200']))->assertOk();
    $image = imagecreatefromwebp($response->baseResponse->getFile()->getPathname());
    expect([imagesx($image), imagesy($image)])->toBe([300, 100])
        ->and(imagecolorsforindex($image, imagecolorat($image, 150, 50))['blue'])->toBeGreaterThan(240);
    imagedestroy($image);
});

it('reuses normalized cached variants and supports conditional requests', function () {
    storeVariantTestImage();
    config()->set('image_variants.generations_per_minute', 1);
    $response = $this->get(variantTestUrl())->assertOk();
    $file = $response->baseResponse->getFile()->getPathname();
    $this->get(variantTestUrl(['fit' => 'contain', 'position' => 'top', 'upscale' => 0]))->assertOk();
    $this->get(variantTestUrl(), ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
    expect(Storage::disk('local')->allFiles('image-variants'))->toHaveCount(1);
    expect($file)->toBe($response->baseResponse->getFile()->getPathname());
    $this->get(variantTestUrl(['width' => 299]))->assertStatus(429)->assertHeader('Retry-After');
});

it('invalidates cached results when the source changes and never serves deleted originals', function () {
    storeVariantTestImage();
    $first = $this->get(variantTestUrl())->assertOk();
    storeVariantTestImage(width: 600, height: 300);
    clearstatcache();
    $second = $this->get(variantTestUrl())->assertOk();
    expect($second->headers->get('ETag'))->not->toBe($first->headers->get('ETag'));
    Storage::disk('public')->delete('activity-types/banner.png');
    $this->get(variantTestUrl())->assertNotFound();
});

it('rejects paths outside approved public image directories without fetching anything', function (string $path) {
    $this->get(variantTestUrl(['path' => $path]))->assertNotFound();
    Http::assertNothingSent();
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(['../.env', 'groups/../../private/secret.png', 'groups/..\\private.png', '/groups/image.png', 'https://example.com/image.png', 'group-resources/1/private.png', 'groups/%2e%2e/private.png', 'groups/image.svg', 'groups/image.gif', 'groups/missing.png']);

it('rejects malformed or excessive options as 422 even for regular image requests', function (array $query) {
    storeVariantTestImage();
    $this->get(variantTestUrl($query))->assertStatus(422);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    [['width' => 0]], [['width' => 2049]], [['width' => 'foo']], [['width' => [300]]],
    [['height' => -1]], [['fit' => 'stretch']], [['fit' => 'crop']], [['position' => 'secret']],
    [['crop' => '-1,0,10,10']], [['crop' => '0,0,0,1']], [['crop' => '590,0,20,20']],
]);

it('rejects disguised files and excessive source dimensions before decoding', function () {
    Storage::disk('public')->put('activity-types/banner.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $this->get(variantTestUrl())->assertStatus(415);
    storeVariantTestImage();
    $binary = Storage::disk('public')->get('activity-types/banner.png');
    $binary = substr_replace($binary, pack('NN', 6000, 6000), 16, 8);
    $binary = substr_replace($binary, pack('N', crc32(substr($binary, 12, 17))), 29, 4);
    Storage::disk('public')->put('activity-types/banner.png', $binary);
    $this->get(variantTestUrl())->assertStatus(413);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('bounds source bytes and requires at least one output dimension', function () {
    storeVariantTestImage();
    $this->get(route('images.transform', ['path' => 'activity-types/banner.png']))->assertStatus(422);
    config()->set('image_variants.max_source_bytes', 1);
    $this->get(variantTestUrl())->assertStatus(413);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('bounds inferred output dimensions before allocating a canvas', function () {
    storeVariantTestImage(width: 6000, height: 3);
    $this->get(route('images.transform', ['path' => 'activity-types/banner.png', 'height' => 2048, 'upscale' => 1]))->assertStatus(422);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('limits variant count and prunes only cached derivatives', function () {
    storeVariantTestImage();
    config()->set('image_variants.max_variants_per_image', 2);
    foreach ([100, 200, 300] as $width) {
        $this->get(variantTestUrl(['width' => $width]))->assertOk();
    }
    expect(Storage::disk('local')->allFiles('image-variants'))->toHaveCount(2);
    Storage::disk('local')->put('group-resources/private.png', 'private');
    config()->set('image_variants.cache_max_bytes', 0);
    $this->artisan('images:prune-variants')->expectsOutput('Removed 2 cached image variants.')->assertSuccessful();
    expect(Storage::disk('local')->allFiles('image-variants'))->toBe([]);
    Storage::disk('local')->assertExists('group-resources/private.png');
    Storage::disk('public')->assertExists('activity-types/banner.png');
});

it('prunes expired variants even below the cache size budget', function () {
    storeVariantTestImage();
    $response = $this->get(variantTestUrl())->assertOk();
    $file = $response->baseResponse->getFile()->getPathname();
    touch($file, now()->subDays(8)->timestamp);
    clearstatcache();
    $this->artisan('images:prune-variants')->expectsOutput('Removed 1 cached image variants.')->assertSuccessful();
    expect(Storage::disk('local')->allFiles('image-variants'))->toBe([]);
    $this->get(variantTestUrl())->assertOk();
});

it('generates URLs only for local managed public images', function () {
    $service = app(ImageVariantService::class);
    $options = new ImageTransformOptions(width: 1200, height: 400, fit: 'crop', upscale: true);
    $url = $service->url(Storage::disk('public')->url('activity-types/banner.webp'), $options);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect(parse_url($url, PHP_URL_PATH))->toBe('/images/transform')
        ->and($query)->toBe(['path' => 'activity-types/banner.webp', 'width' => '1200', 'height' => '400', 'fit' => 'crop', 'position' => 'center', 'upscale' => '1']);
    foreach ([null, '', 'https://example.com/storage/groups/image.png', '/storage/../private/image.png', '/resource-assets/private-id', 'data:image/png;base64,aGVsbG8=', '/storage/groups/image.gif'] as $source) {
        expect($service->url($source, $options))->toBeNull();
    }
    Http::assertNothingSent();
});
