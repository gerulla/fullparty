<?php

use App\Models\ActivityType;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Models\User;
use App\Services\ManagedImageStorage;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function activityTypeAdminPayload(CharacterClass $characterClass, PhantomJob $phantomBard, PhantomJob $phantomZerker): array
{
    return [
        'slug' => 'forked-tower',
        'draft_name' => [
            'en' => 'Forked Tower',
            'de' => 'Forked Tower',
            'fr' => 'Forked Tower',
            'ja' => 'Forked Tower',
        ],
        'draft_description' => [
            'en' => 'A configurable activity type for roster summary presets.',
            'de' => '',
            'fr' => '',
            'ja' => '',
        ],
        'draft_difficulty' => ActivityType::DIFFICULTY_SAVAGE,
        'draft_default_min_item_level' => 710,
        'tags' => ['forked-tower'],
        'draft_layout_schema' => [
            'groups' => [
                [
                    'key' => 'party-a',
                    'label' => [
                        'en' => 'Party A',
                        'de' => '',
                        'fr' => '',
                        'ja' => '',
                    ],
                    'size' => 8,
                ],
                [
                    'key' => 'party-b',
                    'label' => [
                        'en' => 'Party B',
                        'de' => '',
                        'fr' => '',
                        'ja' => '',
                    ],
                    'size' => 8,
                ],
                [
                    'key' => 'party-c',
                    'label' => [
                        'en' => 'Party C',
                        'de' => '',
                        'fr' => '',
                        'ja' => '',
                    ],
                    'size' => 8,
                ],
                [
                    'key' => 'party-d',
                    'label' => [
                        'en' => 'Party D',
                        'de' => '',
                        'fr' => '',
                        'ja' => '',
                    ],
                    'size' => 8,
                ],
                [
                    'key' => 'party-e',
                    'label' => [
                        'en' => 'Party E',
                        'de' => '',
                        'fr' => '',
                        'ja' => '',
                    ],
                    'size' => 8,
                ],
                [
                    'key' => 'party-f',
                    'label' => [
                        'en' => 'Party F',
                        'de' => '',
                        'fr' => '',
                        'ja' => '',
                    ],
                    'size' => 8,
                ],
            ],
        ],
        'draft_slot_schema' => [
            [
                'key' => 'character_class',
                'label' => [
                    'en' => 'Character Class',
                    'de' => '',
                    'fr' => '',
                    'ja' => '',
                ],
                'type' => 'single_select',
                'source' => 'character_classes',
                'required' => true,
            ],
            [
                'key' => 'phantom_job',
                'label' => [
                    'en' => 'Phantom Job',
                    'de' => '',
                    'fr' => '',
                    'ja' => '',
                ],
                'type' => 'single_select',
                'source' => 'phantom_jobs',
                'required' => true,
            ],
        ],
        'draft_application_schema' => [
            [
                'key' => 'character_class',
                'label' => [
                    'en' => 'Can Play',
                    'de' => '',
                    'fr' => '',
                    'ja' => '',
                ],
                'type' => 'multi_select',
                'source' => 'character_classes',
                'required' => true,
                'accepts_any' => true,
                'any_label' => [
                    'en' => 'Put Me Anywhere Coach',
                    'de' => '',
                    'fr' => '',
                    'ja' => '',
                ],
            ],
            [
                'key' => 'phantom_job',
                'label' => [
                    'en' => 'Preferred Phantom Job',
                    'de' => '',
                    'fr' => '',
                    'ja' => '',
                ],
                'type' => 'multi_select',
                'source' => 'phantom_jobs',
                'required' => true,
            ],
        ],
        'draft_roster_summary_presets' => [
            [
                'key' => 'minimum-composition',
                'label' => [
                    'en' => 'Minimum Composition',
                    'de' => '',
                    'fr' => '',
                    'ja' => '',
                ],
                'description' => [
                    'en' => 'Checks the minimum essentials needed for a clean clear.',
                    'de' => '',
                    'fr' => '',
                    'ja' => '',
                ],
                'requirements' => [
                    [
                        'source' => 'phantom_jobs',
                        'source_id' => $phantomBard->id,
                        'comparison' => 'at_least',
                        'target_count' => 1,
                        'scope_type' => 'slot_group_set',
                        'scope_group_keys' => ['party-a', 'party-b', 'party-c'],
                    ],
                    [
                        'source' => 'phantom_jobs',
                        'source_id' => $phantomBard->id,
                        'comparison' => 'at_least',
                        'target_count' => 1,
                        'scope_type' => 'slot_group_set',
                        'scope_group_keys' => ['party-d', 'party-e', 'party-f'],
                    ],
                    [
                        'source' => 'phantom_jobs',
                        'source_id' => $phantomZerker->id,
                        'comparison' => 'at_least',
                        'target_count' => 1,
                        'scope_type' => 'all_slots',
                        'scope_group_keys' => [],
                    ],
                ],
            ],
        ],
        'draft_progress_schema' => [
            'milestones' => [],
        ],
        'draft_bench_size' => 0,
        'draft_prog_points' => [],
        'draft_fflogs_zone_id' => null,
        'is_active' => true,
    ];
}

function activityTypePublicStoragePath(?string $url): string
{
    $path = parse_url((string) $url, PHP_URL_PATH);

    expect($path)->toBeString();

    return substr((string) $path, strlen('/storage/'));
}

it('paginates and searches the admin activity type index', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    ActivityType::factory()
        ->count(13)
        ->sequence(fn (Sequence $sequence) => [
            'slug' => sprintf('filler-type-%02d', $sequence->index),
            'draft_name' => ['en' => sprintf('Filler Type %02d', $sequence->index)],
            'updated_at' => now()->subMinutes($sequence->index + 1),
        ])
        ->create();

    $matchingType = ActivityType::factory()->create([
        'slug' => 'needle-raid',
        'draft_name' => ['en' => 'Needle Raid'],
        'draft_description' => ['en' => 'Search target for admin pagination.'],
        'draft_difficulty' => ActivityType::DIFFICULTY_ULTIMATE,
        'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.activity-types.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('activityTypes.data', 12)
            ->where('activityTypes.meta.current_page', 1)
            ->where('activityTypes.meta.per_page', 12)
            ->where('activityTypes.meta.total', 14)
            ->where('filters.search', '')
        );

    $this->actingAs($admin)
        ->get(route('admin.activity-types.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('activityTypes.data', 2)
            ->where('activityTypes.meta.current_page', 2)
            ->where('activityTypes.meta.total', 14)
        );

    $this->actingAs($admin)
        ->get(route('admin.activity-types.index', ['search' => 'needle']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('activityTypes.data', 1)
            ->where('activityTypes.data.0.id', $matchingType->id)
            ->where('activityTypes.meta.total', 1)
            ->where('filters.search', 'needle')
        );
});

it('allows admins to save roster summary presets on activity type drafts', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $characterClass = CharacterClass::create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);

    $phantomBard = PhantomJob::create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
    ]);

    $phantomZerker = PhantomJob::create([
        'name' => 'Phantom Zerker',
        'max_level' => 20,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.activity-types.store'), activityTypeAdminPayload($characterClass, $phantomBard, $phantomZerker))
        ->assertRedirect(route('admin.activity-types.index'));

    $activityType = ActivityType::query()->where('slug', 'forked-tower')->sole();

    expect($activityType->draft_roster_summary_presets)->toHaveCount(1)
        ->and($activityType->draft_application_schema[0]['accepts_any'])->toBeTrue()
        ->and($activityType->draft_application_schema[0]['any_label']['en'])->toBe('Put Me Anywhere Coach')
        ->and($activityType->draft_roster_summary_presets[0]['key'])->toBe('minimum-composition')
        ->and($activityType->draft_roster_summary_presets[0]['requirements'])->toHaveCount(3)
        ->and($activityType->draft_roster_summary_presets[0]['requirements'][0])->toMatchArray([
            'source' => 'phantom_jobs',
            'source_id' => $phantomBard->id,
            'comparison' => 'at_least',
            'target_count' => 1,
            'scope_type' => 'slot_group_set',
            'scope_group_keys' => ['party-a', 'party-b', 'party-c'],
        ]);
});

it('stores activity type images and snapshots copies into published versions', function () {
    Storage::fake('public');

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $characterClass = CharacterClass::create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);

    $phantomBard = PhantomJob::create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
    ]);

    $phantomZerker = PhantomJob::create([
        'name' => 'Phantom Zerker',
        'max_level' => 20,
    ]);

    $payload = activityTypeAdminPayload($characterClass, $phantomBard, $phantomZerker);
    $payload['draft_small_image'] = UploadedFile::fake()->image('small-card.png', 1000, 1700);
    $payload['draft_banner_image'] = UploadedFile::fake()->image('banner.png', 1500, 500);

    $this->actingAs($admin)
        ->post(route('admin.activity-types.store'), $payload)
        ->assertRedirect(route('admin.activity-types.index'));

    $activityType = ActivityType::query()->where('slug', 'forked-tower')->sole();
    $draftSmallImagePath = activityTypePublicStoragePath($activityType->draft_small_image_url);
    $draftBannerImagePath = activityTypePublicStoragePath($activityType->draft_banner_image_url);

    Storage::disk('public')->assertExists($draftSmallImagePath);
    Storage::disk('public')->assertExists($draftBannerImagePath);

    $this->actingAs($admin)
        ->post(route('admin.activity-types.publish', $activityType))
        ->assertRedirect();

    $activityType->refresh()->load('currentPublishedVersion');
    $publishedVersion = $activityType->currentPublishedVersion;

    $publishedSmallImagePath = activityTypePublicStoragePath($publishedVersion?->small_image_url);
    $publishedBannerImagePath = activityTypePublicStoragePath($publishedVersion?->banner_image_url);

    expect($publishedSmallImagePath)->not->toBe($draftSmallImagePath)
        ->and($publishedBannerImagePath)->not->toBe($draftBannerImagePath);

    Storage::disk('public')->assertExists($publishedSmallImagePath);
    Storage::disk('public')->assertExists($publishedBannerImagePath);
});

it('stores uploaded admin images with a server-detected image extension instead of the client filename extension', function () {
    Storage::fake('public');

    $storedUrl = app(ManagedImageStorage::class)->uploadImageIfPresent(
        UploadedFile::fake()->image('shell.php', 600, 600),
        'activity-types',
    );
    $draftSmallImagePath = activityTypePublicStoragePath($storedUrl);

    expect($draftSmallImagePath)->toEndWith('.webp')
        ->not->toEndWith('.php');

    Storage::disk('public')->assertExists($draftSmallImagePath);

    $imageInfo = getimagesizefromstring(Storage::disk('public')->get($draftSmallImagePath));

    expect($imageInfo['mime'] ?? null)->toBe('image/webp');
});

it('rejects svg uploads for activity type images', function () {
    Storage::fake('public');

    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $characterClass = CharacterClass::create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);

    $phantomBard = PhantomJob::create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
    ]);

    $phantomZerker = PhantomJob::create([
        'name' => 'Phantom Zerker',
        'max_level' => 20,
    ]);

    $payload = activityTypeAdminPayload($characterClass, $phantomBard, $phantomZerker);
    $payload['draft_small_image'] = UploadedFile::fake()->createWithContent(
        'payload.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $this
        ->actingAs($admin)
        ->from(route('admin.activity-types.create'))
        ->post(route('admin.activity-types.store'), $payload)
        ->assertRedirect(route('admin.activity-types.create'))
        ->assertSessionHasErrors('draft_small_image');

    expect(ActivityType::query()->where('slug', 'forked-tower')->doesntExist())->toBeTrue();
});

it('publishes roster summary presets into the activity type version snapshot', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $characterClass = CharacterClass::create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);

    $phantomBard = PhantomJob::create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
    ]);

    $phantomZerker = PhantomJob::create([
        'name' => 'Phantom Zerker',
        'max_level' => 20,
    ]);

    $payload = activityTypeAdminPayload($characterClass, $phantomBard, $phantomZerker);

    $activityType = ActivityType::factory()->create([
        'created_by_user_id' => $admin->id,
        'slug' => $payload['slug'],
        'draft_name' => $payload['draft_name'],
        'draft_description' => $payload['draft_description'],
        'draft_difficulty' => $payload['draft_difficulty'],
        'draft_default_min_item_level' => $payload['draft_default_min_item_level'],
        'draft_layout_schema' => $payload['draft_layout_schema'],
        'draft_slot_schema' => $payload['draft_slot_schema'],
        'draft_application_schema' => $payload['draft_application_schema'],
        'draft_roster_summary_presets' => $payload['draft_roster_summary_presets'],
        'draft_progress_schema' => $payload['draft_progress_schema'],
        'draft_bench_size' => $payload['draft_bench_size'],
        'draft_prog_points' => $payload['draft_prog_points'],
        'draft_fflogs_zone_id' => $payload['draft_fflogs_zone_id'],
    ]);

    $this->actingAs($admin)
        ->post(route('admin.activity-types.publish', $activityType))
        ->assertRedirect();

    $activityType->refresh()->load('currentPublishedVersion');
    $publishedVersion = $activityType->currentPublishedVersion;

    expect($publishedVersion)->not->toBeNull()
        ->and($publishedVersion?->roster_summary_presets)->toBe($activityType->draft_roster_summary_presets)
        ->and($publishedVersion?->difficulty)->toBe(ActivityType::DIFFICULTY_SAVAGE)
        ->and($publishedVersion?->default_min_item_level)->toBe(710);
});

it('rejects roster summary requirements that target unknown layout groups', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $characterClass = CharacterClass::create([
        'name' => 'White Mage',
        'shorthand' => 'WHM',
        'role' => 'healer',
    ]);

    $phantomBard = PhantomJob::create([
        'name' => 'Phantom Bard',
        'max_level' => 20,
    ]);

    $phantomZerker = PhantomJob::create([
        'name' => 'Phantom Zerker',
        'max_level' => 20,
    ]);

    $payload = activityTypeAdminPayload($characterClass, $phantomBard, $phantomZerker);
    $payload['draft_roster_summary_presets'][0]['requirements'][0]['scope_group_keys'] = ['party-z'];

    $this->actingAs($admin)
        ->from(route('admin.activity-types.create'))
        ->post(route('admin.activity-types.store'), $payload)
        ->assertRedirect(route('admin.activity-types.create'))
        ->assertSessionHasErrors('draft_roster_summary_presets.0.requirements.0.scope_group_keys');
});
