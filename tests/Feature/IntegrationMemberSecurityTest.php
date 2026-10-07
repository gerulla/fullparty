<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Integrations\MemberRunReader;
use App\Services\ManagedImageStorage;
use App\Support\Integrations\IntegrationPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->member = User::factory()->create();
    DiscordUserIntegration::create(['user_id' => $this->member->id, 'discord_user_id' => '123456789012345678', 'user_app_installed_at' => now()]);
    $token = IntegrationClient::makePlainApiToken();
    IntegrationClient::factory()->withApiToken($token)->create(['scopes' => [IntegrationPermissions::MEMBERS_READ, IntegrationPermissions::MEMBERS_WRITE]]);
    $this->headers = ['Authorization' => 'Bearer '.$token, 'X-FullParty-Discord-User-Id' => '123456789012345678'];
});

it('keeps application history while redacting runs the actor can no longer read', function (string $restriction) {
    $group = Group::factory()->withMember($this->member)->create(['is_visible' => $restriction !== 'left_private_group']);
    $run = Activity::factory()->create(['group_id' => $group->id, 'status' => Activity::STATUS_SCHEDULED]);
    $application = ActivityApplication::factory()->create([
        'activity_id' => $run->id, 'user_id' => $this->member->id,
        'status' => ActivityApplication::STATUS_WITHDRAWN, 'notes' => 'My application notes',
    ]);
    $path = '/api/integrations/v1/me/applications/'.$application->id;
    $this->getJson($path, $this->headers)->assertOk()->assertJsonPath('data.run.id', $run->id);

    if ($restriction === 'banned') {
        $group->bans()->create(['user_id' => $this->member->id, 'banned_by_user_id' => $group->owner_id]);
    } elseif ($restriction === 'left_private_group') {
        $group->memberships()->where('user_id', $this->member->id)->delete();
    } else {
        $run->update(['status' => Activity::STATUS_DRAFT]);
    }
    $run->update(['notes' => 'Private update after access was revoked']);

    $this->getJson('/api/integrations/v1/runs/'.$run->id, $this->headers)->assertNotFound();
    $this->getJson($path, $this->headers)->assertOk()
        ->assertJsonPath('data.id', $application->id)
        ->assertJsonPath('data.notes', 'My application notes')
        ->assertJsonPath('data.run', null);
    $this->getJson('/api/integrations/v1/me/applications', $this->headers)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.run', null);
})->with(['banned', 'left_private_group', 'draft']);

it('loads visible application runs with a constant number of queries', function () {
    ActivityApplication::factory()->count(5)->create([
        'user_id' => $this->member->id,
        'activity_id' => Activity::factory()->state(['status' => Activity::STATUS_SCHEDULED]),
    ]);
    $reader = app(MemberRunReader::class);
    DB::enableQueryLog();
    try {
        DB::flushQueryLog();
        $reader->applications($this->member)->limit(1)->get()->map($reader->application(...));
        $singleCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $items = $reader->applications($this->member)->get()->map($reader->application(...));
        expect(count(DB::getQueryLog()))->toBe($singleCount);
        expect($items->pluck('run')->filter())->toHaveCount(5);
    } finally {
        DB::disableQueryLog();
    }
});

it('rejects malformed availability structures as validation errors', function (array $change, string $field) {
    $group = Group::factory()->withMember($this->member)->create();
    $group->features()->update(['availability_scheduler_enabled' => true]);
    $payload = [
        'cycle_weeks' => 1, 'repeats' => true, 'lock_weekends' => false, 'on_hiatus' => false,
        'starts_on' => now()->toDateString(), 'timezone' => 'UTC', 'windows' => [], 'exceptions' => [],
        ...$change,
    ];
    $this->putJson('/api/integrations/v1/groups/'.$group->slug.'/availability', $payload, $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($group->availabilitySchedules()->count())->toBe(0);
})->with([
    [['windows' => ['invalid']], 'windows.0'],
    [['windows' => 'invalid'], 'windows'],
    [['exceptions' => ['invalid']], 'exceptions.0'],
    [['exceptions' => [['date' => '2099-01-01', 'starts_at' => 'invalid', 'ends_at' => '18:00']]], 'exceptions.0.starts_at'],
]);

it('normalizes multipart profile reset booleans without coercing invalid values', function (string $value, ?bool $expected) {
    Storage::fake('public');
    Storage::disk('public')->put('home-profiles/existing.webp', 'existing');
    $this->member->homeProfile()->create(['background_image_url' => '/storage/home-profiles/existing.webp']);
    $response = $this->post('/api/integrations/v1/me/profile', ['reset_background_image' => $value], $this->headers);
    if ($expected === null) {
        $response->assertUnprocessable()->assertJsonValidationErrors('reset_background_image');
    } else {
        $response->assertOk();
    }
    expect($this->member->fresh()->homeProfile->background_image_url)
        ->toBe($expected === true ? null : '/storage/home-profiles/existing.webp');
})->with([['true', true], ['false', false], ['1', true], ['0', false], ['not-a-boolean', null]]);

it('rejects oversized avatar and background dimensions before reaching image storage', function (string $endpoint, string $field, int $width, int $height) {
    $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8foAAAAASUVORK5CYII=');
    $binary = substr_replace($binary, pack('NN', $width, $height), 16, 8);
    $binary = substr_replace($binary, pack('N', crc32(substr($binary, 12, 17))), 29, 4);
    $this->mock(ManagedImageStorage::class)->shouldNotReceive('replaceUploadedImageIfPresent');

    $this->post('/api/integrations/v1/me/'.$endpoint, [$field => UploadedFile::fake()->createWithContent('large.png', $binary)], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['avatar', 'profile_picture', 8193, 1],
    ['profile', 'background_image', 6000, 6000],
]);

it('still accepts small png avatar and background uploads', function (string $endpoint, string $field) {
    Storage::fake('public');
    $this->post('/api/integrations/v1/me/'.$endpoint, [$field => UploadedFile::fake()->image('small.png', 32, 24)], $this->headers)
        ->assertOk();
    $url = $endpoint === 'avatar' ? $this->member->fresh()->avatar_url : $this->member->fresh()->homeProfile->background_image_url;
    expect($url)->toEndWith('.webp');
    Storage::disk('public')->assertExists(str_replace('/storage/', '', parse_url($url, PHP_URL_PATH)));
})->with([['avatar', 'profile_picture'], ['profile', 'background_image']]);
