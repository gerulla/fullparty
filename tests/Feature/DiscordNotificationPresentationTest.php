<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityPartyFinderInfo;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\NotificationEvent;
use App\Models\PhantomJob;
use App\Models\User;
use App\Services\Notifications\ActivityNotificationPayloadBuilder;
use App\Services\Notifications\ApplicationNotificationService;
use App\Services\Notifications\AssignmentNotificationService;
use App\Services\Notifications\PartyFinderNotificationPayloadBuilder;
use App\Services\Notifications\RunNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OpenApiContract;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://integration.fullparty.test/events' => Http::response(['ok' => true])]);
    IntegrationClient::factory()->create();
    $this->owner = User::factory()->create(['name' => 'Reviewer']);
    $this->member = User::factory()->create([
        'name' => 'Applicant', 'assignment_notifications' => true,
        'application_notifications' => true, 'run_and_reminder_notifications' => true,
    ]);
    foreach ([$this->owner, $this->member] as $user) {
        DiscordUserIntegration::create(['user_id' => $user->id, 'discord_user_id' => (string) (234567890123456780 + $user->id), 'user_app_installed_at' => now()]);
    }
    $this->group = Group::factory()->create([
        'owner_id' => $this->owner->id, 'name' => 'Example Raiders',
        'profile_picture_url' => Storage::disk('public')->url('groups/logo.webp'),
        'discord_invite_url' => 'https://discord.gg/example',
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'name' => ['en' => 'Published Activity'],
        'banner_image_url' => Storage::disk('public')->url('activity-types/banner.webp'),
        'layout_schema' => ['groups' => [['key' => 'party-a', 'label' => ['en' => 'Party A'], 'size' => 1]]],
        'slot_schema' => [], 'application_schema' => [], 'bench_size' => 0,
    ]);
    $this->activity = Activity::factory()->create([
        'group_id' => $this->group->id, 'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $this->owner->id, 'status' => Activity::STATUS_ASSIGNED,
        'title' => 'Friday Progression', 'starts_at' => now()->addMinutes(30),
    ]);
    $this->character = Character::factory()->create([
        'user_id' => $this->member->id, 'name' => 'Ari Vale', 'world' => 'Twintania',
        'avatar_url' => 'https://img2.finalfantasyxiv.com/f/ari.jpg',
    ]);
    Character::factory()->primary()->create(['user_id' => $this->member->id, 'world' => 'Gilgamesh']);
    $this->application = ActivityApplication::factory()->approved($this->owner)->create([
        'activity_id' => $this->activity->id, 'user_id' => $this->member->id,
        'selected_character_id' => $this->character->id,
        'applicant_lodestone_id' => $this->character->lodestone_id,
    ]);
    $this->slot = $this->activity->slots()->firstOrFail();
    $this->slot->update(['assigned_character_id' => $this->character->id]);
    $this->assertPresentation = function (array $payload): void {
        expect($payload)->toMatchArray([
            'run_title' => 'Friday Progression', 'group_name' => 'Example Raiders',
            'discord_url' => 'https://discord.gg/example',
            'starts_at' => $this->activity->starts_at->toIso8601String(),
        ]);
        expect($payload['run_url'])->toContain('/groups/'.$this->group->slug.'/activities/'.$this->activity->id);
        parse_str(parse_url($payload['banner_image_url'], PHP_URL_QUERY), $banner);
        parse_str(parse_url($payload['group_icon_url'], PHP_URL_QUERY), $icon);
        expect($banner)->toMatchArray(['path' => 'activity-types/banner.webp', 'width' => '1200', 'height' => '400', 'fit' => 'crop', 'position' => 'center'])
            ->and($icon)->toMatchArray(['path' => 'groups/logo.webp', 'width' => '256', 'height' => '256']);
    };
});

it('includes applicant identity rather than reviewer identity and preserves existing application event names', function () {
    $service = app(ApplicationNotificationService::class);
    $service->notifySubmitted($this->application, $this->member);
    $service->notifyUpdated($this->application, $this->member);
    $service->notifyWithdrawn($this->application, $this->member);
    foreach (NotificationEvent::all() as $event) {
        ($this->assertPresentation)($event->payload);
        expect($event->payload['applicant_name'])->toBe('Applicant')
            ->and($event->payload['applicant_profile_url'])->toBeNull()
            ->and($event->payload['character_world'])->toBe('Twintania');
        $hostNotification = in_array($event->type, ['applications.new_for_review', 'applications.updated', 'applications.withdrawn_for_review'], true);
        expect($event->payload['application_url'])->toContain($hostNotification ? '/groups/'.$this->group->slug.'/dashboard/activities/' : '/account/applications');
    }
    $types = Http::recorded()->map(fn ($entry) => $entry[0]->data()['data']['type'])->all();
    expect($types)->toBe(['applications.submitted', 'applications.updated', 'applications.withdrawn_for_review', 'applications.withdrawal_confirmed']);
});

it('enriches every assignment variant and provides usable class and phantom icons', function (bool $scalarSelection) {
    $class = CharacterClass::forceCreate(['id' => 90001, 'name' => 'White Mage', 'shorthand' => 'WHM', 'role' => 'healer', 'icon_url' => '/reference-icons/whm.webp']);
    $phantom = PhantomJob::forceCreate([
        'id' => 90001, 'name' => 'Phantom Knight', 'max_level' => 6,
        'icon_url' => '/reference-icons/phantom-jobs/icons/phantom-knight.webp',
        'transparent_icon_url' => '/reference-icons/phantom-jobs/transparent-icons/phantom-knight.webp',
    ]);
    foreach (['character_classes' => $class, 'phantom_jobs' => $phantom] as $source => $record) {
        $this->slot->fieldValues()->create([
            'field_key' => $source, 'field_label' => ['en' => $source],
            'field_type' => 'single_select', 'source' => $source,
            'value' => $scalarSelection ? $record->id : ['id' => $record->id],
        ]);
    }
    $service = app(AssignmentNotificationService::class);
    $service->notifyPlacementChanged($this->application, $this->slot, $this->owner);
    $service->sendRosterPublishedNotification($this->slot->id, $this->application->id, null, $this->owner->id);
    $this->application->update(['status' => ActivityApplication::STATUS_ON_BENCH]);
    $service->notifyPlacementChanged($this->application, $this->slot, $this->owner);
    $service->sendRosterPublishedNotification($this->slot->id, $this->application->id, null, $this->owner->id);
    $this->application->update(['status' => ActivityApplication::STATUS_PENDING]);
    $service->notifyPlacementChanged($this->application, null, $this->owner);
    $service->notifyMarkedMissing($this->activity, $this->character, $this->slot, $this->owner);
    $service->notifyMissingRestored($this->activity, $this->character, $this->slot, $this->owner);
    $service->notifyDesignationChanged($this->activity, $this->character, $this->slot, 'trapper', true, $this->owner);
    $service->notifyDesignationChanged($this->activity, $this->character, $this->slot, 'trapper', false, $this->owner);
    $service->notifyManualPlacementChanged($this->activity, $this->character, $this->slot, $this->owner);
    $service->sendRosterPublishedNotification($this->slot->id, null, $this->character->id, $this->owner->id);
    Http::assertSentCount(11);
    $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach (Http::recorded() as [$request]) {
        $payload = $request->data()['data']['notification']['payload'];
        ($this->assertPresentation)($payload);
        expect($payload['character_world'])->toBe('Twintania')->and($payload['character_avatar_url'])->toBe($this->character->avatar_url);
        if (isset($payload['roster']['fields'])) {
            $transparentIcon = rtrim(config('app.url'), '/').'/reference-icons/phantom-jobs/transparent-icons/phantom-knight.webp';
            expect(array_column(array_column($payload['roster']['fields'], 'meta'), 'icon_url'))
                ->toBe([rtrim(config('app.url'), '/').'/reference-icons/whm.webp', $transparentIcon])
                ->and($payload['roster']['selected_phantom_job']['icon_url'])->toBe($transparentIcon)
                ->and($payload['roster']['selected_phantom_job']['transparent_icon_url'])->toBe($transparentIcon);
        }
        OpenApiContract::assertMatches(json_decode($request->body()), $document['webhooks']['discord.notification.delivery']['post']['requestBody']['content']['application/json']['schema'], $document);
    }
})->with([false, true]);

it('does not substitute an opaque phantom job icon when the transparent variant is missing', function () {
    $phantom = PhantomJob::forceCreate([
        'id' => 90002, 'name' => 'Phantom Monk', 'max_level' => 6,
        'icon_url' => '/reference-icons/phantom-jobs/icons/phantom-monk.webp',
        'transparent_icon_url' => null,
    ]);
    $this->slot->fieldValues()->create([
        'field_key' => 'phantom_job', 'field_label' => ['en' => 'Phantom Job'],
        'field_type' => 'single_select', 'source' => 'phantom_jobs', 'value' => ['id' => $phantom->id],
    ]);
    app(AssignmentNotificationService::class)->notifyPlacementChanged($this->application, $this->slot, $this->owner);

    Http::assertSentCount(1);
    $payload = Http::recorded()->sole()[0]->data()['data']['notification']['payload'];
    expect($payload['roster']['fields'][0]['meta']['icon_url'])->toBeNull()
        ->and($payload['roster']['selected_phantom_job'])->not->toHaveKey('icon_url');
});

it('enriches all run notifications and includes top level run links in guild automation', function () {
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456789012345678', 'guild_installed_at' => now()]);
    $service = app(RunNotificationService::class);
    $service->dispatchDueReminders();
    $this->travel(31)->minutes();
    $service->dispatchDueReminders();
    $info = ActivityPartyFinderInfo::create([
        'activity_id' => $this->activity->id, 'character_name' => 'Ari Vale', 'world' => 'Lich',
        'password' => '0042', 'published_by_user_id' => $this->member->id, 'published_at' => now(),
    ]);
    $service->notifyPartyFinderPublished($this->activity, $info, $this->owner);
    $service->notifyCompleted($this->activity, $this->owner);
    $service->notifyCancelled($this->activity, $this->owner);
    Http::assertSentCount(9);
    $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach (Http::recorded() as [$request]) {
        $body = $request->data();
        if ($body['event'] === 'discord.notification.delivery') {
            ($this->assertPresentation)($body['data']['notification']['payload']);
            if ($body['data']['type'] === 'runs.party_finder_published') {
                expect($body['data']['notification']['payload']['party_finder'])->toMatchArray([
                    'world' => 'Lich', 'datacenter' => 'Light', 'region' => 'EU', 'character_world' => 'Twintania', 'password' => '0042',
                ]);
            }
        } else {
            expect($body['data']['run_url'])->toBe(app(ActivityNotificationPayloadBuilder::class)->runUrl($this->activity));
            expect(parse_url($body['data']['run_url'], PHP_URL_HOST))->not->toBeEmpty();
        }
        OpenApiContract::assertMatches(json_decode($request->body()), $document['webhooks'][$body['event']]['post']['requestBody']['content']['application/json']['schema'], $document);
    }
});

it('does not guess a Party Finder home world or unknown listing datacenter', function (string $scenario) {
    $this->character->update(['verified_at' => $scenario === 'unverified' ? null : now()]);
    if ($scenario === 'ambiguous') {
        Character::factory()->create(['user_id' => $this->member->id, 'name' => 'Ari Vale', 'world' => 'Ragnarok', 'verified_at' => now()]);
    }
    $info = new ActivityPartyFinderInfo([
        'character_name' => 'Ari Vale', 'world' => 'Unknown World',
        'published_by_user_id' => $scenario === 'other owner' ? $this->owner->id : $this->member->id,
    ]);
    expect(app(PartyFinderNotificationPayloadBuilder::class)->build($info))->toMatchArray([
        'datacenter' => null, 'region' => null, 'character_world' => null,
    ]);
})->with(['unverified', 'ambiguous', 'other owner']);

it('keeps optional details null and uses published names when no custom title exists', function () {
    $this->group->update(['profile_picture_url' => null, 'discord_invite_url' => null]);
    $this->activity->update(['title' => null, 'starts_at' => null]);
    $this->activity->activityTypeVersion->update(['banner_image_url' => null]);
    expect(app(ActivityNotificationPayloadBuilder::class)->forActivity($this->activity->fresh()))->toMatchArray([
        'run_title' => 'Published Activity', 'group_icon_url' => null, 'banner_image_url' => null, 'discord_url' => null, 'starts_at' => null,
    ]);
    expect(app(ActivityNotificationPayloadBuilder::class)->publicUrl('javascript:alert(1)'))->toBeNull();
    Http::assertNothingSent();
});

it('covers every configured world exactly once in the datacenter mapping', function () {
    $worlds = collect(config('datacenters.worlds'))->flatten();
    expect($worlds->unique()->count())->toBe($worlds->count())
        ->and($worlds->sort()->values()->all())->toBe(collect(config('lodestone_worlds.values'))->sort()->values()->all());
});
