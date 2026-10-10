<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\RunNotificationService;
use App\Services\Notifications\RunReminderContactPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OpenApiContract;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://integration.fullparty.test/events' => Http::response([], 204)]);
    IntegrationClient::factory()->create();

    $this->makeContact = function (string $name): Character {
        $user = User::factory()->create(['run_and_reminder_notifications' => false]);
        DiscordUserIntegration::create([
            'user_id' => $user->id,
            'discord_user_id' => (string) (234567890123456780 + $user->id),
            'user_app_installed_at' => now(),
        ]);

        return Character::factory()->primary()->create([
            'user_id' => $user->id, 'name' => $name, 'world' => 'Twintania',
        ]);
    };
    $this->hostPrimary = ($this->makeContact)('Host Primary');
    $this->hostCharacter = Character::factory()->create([
        'user_id' => $this->hostPrimary->user_id, 'name' => 'Host On Run', 'world' => 'Ragnarok',
        'is_primary' => false,
    ]);
    $group = Group::factory()->create(['owner_id' => $this->hostPrimary->user_id]);
    DiscordGuildIntegration::create([
        'group_id' => $group->id, 'discord_guild_id' => '123456789012345678', 'guild_installed_at' => now(),
    ]);
    $version = ActivityTypeVersion::factory()->create([
        'layout_schema' => ['groups' => []], 'slot_schema' => [], 'application_schema' => [], 'bench_size' => 0,
    ]);
    $this->activity = Activity::factory()->create([
        'group_id' => $group->id, 'activity_type_version_id' => $version->id,
        'organized_by_user_id' => $this->hostPrimary->user_id,
        'organized_by_character_id' => $this->hostCharacter->id,
        'status' => Activity::STATUS_ASSIGNED, 'starts_at' => now()->addMinutes(30),
    ]);
    $this->assign = function (Character $character, string $party, array $overrides = []): ActivitySlot {
        return ActivitySlot::factory()->assignedTo($character)->create(array_merge([
            'activity_id' => $this->activity->id, 'group_key' => $party,
            'slot_key' => $party.'-'.$character->id,
        ], $overrides));
    };
});

it('adds the recipients party leads and configured run host to both discord reminder paths', function (string $type, int $minutes) {
    $member = ($this->makeContact)('Participant');
    $member->user->update(['run_and_reminder_notifications' => true, 'discord_notifications' => true]);
    ($this->assign)($member, 'party-a');
    $lead = ($this->makeContact)('Party Lead');
    ($this->assign)($lead, 'party-a', ['is_raid_leader' => true]);
    $partyHost = ($this->makeContact)('Party Host');
    ($this->assign)($partyHost, 'party-a', ['is_host' => true]);
    ($this->assign)(($this->makeContact)('Ordinary Member'), 'party-a');
    ($this->assign)(($this->makeContact)('Other Party Lead'), 'party-b', ['is_raid_leader' => true]);
    ($this->assign)($this->hostCharacter, 'party-c', ['is_host' => true]);
    $this->activity->update(['starts_at' => now()->addMinutes($minutes)]);

    app(RunNotificationService::class)->dispatchDueReminders();

    $bodies = Http::recorded()->map(fn ($entry) => $entry[0]->data());
    $personal = $bodies->firstWhere('event', IntegrationClient::EVENT_DISCORD_NOTIFICATION_DELIVERY);
    $guild = $bodies->firstWhere('event', IntegrationClient::EVENT_DISCORD_GUILD_RUN_REMINDER);
    expect($personal['data']['type'])->toBe('runs.'.$type)
        ->and($guild['data']['reminder_type'])->toBe($type);

    $contacts = $personal['data']['notification']['payload'];
    expect($contacts['party_leads'])->toBe([
        [
            'user_id' => $lead->user_id, 'discord_user_id' => $lead->user->discordUserIntegration->discord_user_id,
            'character_id' => $lead->id, 'character_name' => 'Party Lead', 'character_world' => 'Twintania',
        ],
        [
            'user_id' => $partyHost->user_id, 'discord_user_id' => $partyHost->user->discordUserIntegration->discord_user_id,
            'character_id' => $partyHost->id, 'character_name' => 'Party Host', 'character_world' => 'Twintania',
        ],
    ])->and($contacts['run_host'])->toBe([
        'user_id' => $this->hostPrimary->user_id,
        'discord_user_id' => $this->hostPrimary->user->discordUserIntegration->discord_user_id,
        'character_id' => $this->hostCharacter->id, 'character_name' => 'Host On Run', 'character_world' => 'Ragnarok',
    ]);
    $participant = collect($guild['data']['participants'])->firstWhere('user_id', $member->user_id);
    expect($participant['party_leads'])->toBe($contacts['party_leads'])
        ->and($participant['run_host'])->toBe($contacts['run_host'])
        ->and(NotificationEvent::where('type', 'runs.'.$type)->sole()->payload)->not->toHaveKey('party_leads');

    $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach (Http::recorded() as [$request]) {
        $event = $request->data()['event'];
        OpenApiContract::assertMatches(json_decode($request->body()), $document['webhooks'][$event]['post']['requestBody']['content']['application/json']['schema'], $document);
    }
})->with([['starting_soon', 30], ['starting_now', -5]]);

it('uses the covered party for fill ins and does not invent a party for bench or unassigned recipients', function () {
    $member = ($this->makeContact)('Fill In');
    $slot = ($this->assign)($member, 'fill-ins', [
        'slot_kind' => ActivitySlot::SLOT_KIND_FILL_IN, 'filled_group_key' => 'party-b',
    ]);
    $lead = ($this->makeContact)('Covered Party Lead');
    ($this->assign)($lead, 'party-b', ['is_raid_leader' => true]);
    ($this->assign)(($this->makeContact)('Other Party Lead'), 'party-a', ['is_host' => true]);
    $builder = app(RunReminderContactPayloadBuilder::class);
    $contacts = $builder->forRecipient($this->activity->fresh(), $member->user_id);
    expect(array_column($contacts['party_leads'], 'character_name'))->toBe(['Covered Party Lead']);

    $slot->update(['filled_group_key' => null]);
    expect($builder->forRecipient($this->activity->fresh(), $member->user_id)['party_leads'])->toBe([]);
    $slot->update(['slot_kind' => ActivitySlot::SLOT_KIND_BENCH, 'filled_group_key' => 'party-b']);
    expect($builder->forRecipient($this->activity->fresh(), $member->user_id)['party_leads'])->toBe([]);
    $slot->update(['slot_kind' => ActivitySlot::SLOT_KIND_ROSTER, 'group_key' => 'bench']);
    expect($builder->forRecipient($this->activity->fresh(), $member->user_id)['party_leads'])->toBe([]);
    $slot->update(['assigned_character_id' => null]);
    ActivityApplication::factory()->approved($this->hostPrimary->user)->create([
        'activity_id' => $this->activity->id, 'user_id' => $member->user_id, 'selected_character_id' => $member->id,
    ]);
    expect($builder->forRecipient($this->activity->fresh(), $member->user_id)['party_leads'])->toBe([])
        ->and($contacts['run_host']['character_name'])->toBe('Host On Run');
});

it('keeps in game contact names without exposing revoked or uninstalled discord connections and deduplicates party leads', function () {
    $member = ($this->makeContact)('Participant');
    ($this->assign)($member, 'party-a');
    foreach (['revoked', 'uninstalled', 'missing'] as $state) {
        $lead = ($this->makeContact)($state.' Lead');
        $integration = $lead->user->discordUserIntegration;
        match ($state) {
            'revoked' => $integration->update(['revoked_at' => now()]),
            'uninstalled' => $integration->update(['user_app_installed_at' => null]),
            'missing' => $integration->delete(),
        };
        ($this->assign)($lead, 'party-a', ['is_raid_leader' => true]);
    }
    $extra = Character::factory()->create(['user_id' => $lead->user_id]);
    ($this->assign)($extra, 'party-a', ['is_host' => true]);
    $empty = ($this->assign)(($this->makeContact)('Former Lead'), 'party-a', ['is_host' => true]);
    $empty->update(['assigned_character_id' => null]);
    ($this->assign)(($this->makeContact)('Bench Lead'), 'party-a', ['is_host' => true, 'slot_kind' => ActivitySlot::SLOT_KIND_BENCH]);

    $contacts = app(RunReminderContactPayloadBuilder::class)->forRecipient($this->activity->fresh(), $member->user_id);
    expect(array_column($contacts['party_leads'], 'character_name'))->toBe(['revoked Lead', 'uninstalled Lead', 'missing Lead'])
        ->and(array_column($contacts['party_leads'], 'discord_user_id'))->toBe([null, null, null]);
});

it('handles missing host characters and reuses loaded relationships across recipients', function () {
    $this->activity->update(['organized_by_character_id' => null]);
    $activity = $this->activity->fresh();
    $builder = app(RunReminderContactPayloadBuilder::class);
    expect($builder->forRecipient($activity, $this->hostPrimary->user_id)['run_host']['character_name'])->toBe('Host Primary');

    DB::enableQueryLog();
    DB::flushQueryLog();
    $builder->forRecipient($activity, $this->hostPrimary->user_id);
    $builder->forRecipient($activity, $this->hostPrimary->user_id + 100);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toBe([]);

    $this->hostPrimary->update(['is_primary' => false]);
    expect($builder->forRecipient($this->activity->fresh(), $this->hostPrimary->user_id)['run_host']['character_name'])->toBeNull();
    $this->activity->update(['organized_by_user_id' => null]);
    expect($builder->forRecipient($this->activity->fresh(), $this->hostPrimary->user_id))->toBe(['party_leads' => [], 'run_host' => null]);
});
