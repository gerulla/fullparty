<?php

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\Groups\ActivitySlotStateTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    Http::preventStrayRequests();
    $this->botResponse = ['success' => true];
    $this->botStatus = 200;
    Http::fake(['integration.fullparty.test/*' => fn () => Http::response($this->botResponse, $this->botStatus)]);
    $this->group = Group::factory()->open()->create();
    $this->activity = Activity::factory()->create([
        'group_id' => $this->group->id,
        'status' => Activity::STATUS_ASSIGNED,
        'starts_at' => now()->addHour(),
    ]);
    $this->character = Character::factory()->provisional()->create(['name' => 'Character Name', 'world' => 'Twintania']);
    $this->slot = $this->activity->slots()->firstOrFail();
    $this->slot->update(['assigned_character_id' => $this->character->id]);
    $this->guild = DiscordGuildIntegration::create([
        'group_id' => $this->group->id, 'discord_guild_id' => '123456789012345678', 'guild_installed_at' => now(),
    ]);
    $this->client = IntegrationClient::factory()->create();
    $this->url = route('groups.dashboard.activities.discord-participants.store', [
        'group' => $this->group, 'activity' => $this->activity, 'slot' => $this->slot,
    ]);
    $this->input = [
        'discord_user_id' => '234567890123456789',
        'expected_slot_state_token' => app(ActivitySlotStateTokenService::class)->generate($this->slot),
    ];
});

it('sends the signed event at the one-hour boundary without saving the temporary ID', function () {
    $character = $this->character->fresh()->getRawOriginal();
    $slot = $this->slot->fresh()->getRawOriginal();
    $this->actingAs($this->group->owner)->postJson($this->url, $this->input)
        ->assertOk()->assertExactJson(['success' => true]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $signature = 'sha256='.hash_hmac('sha256', $request->header('X-FullParty-Timestamp')[0].'.'.$request->body(), $this->client->webhook_signing_secret);

        return $request['event'] === IntegrationClient::EVENT_DISCORD_GUILD_RUN_PARTICIPANT_SYNC
            && $request['data'] === [
                'discord_guild_id' => '123456789012345678',
                'discord_user_id' => '234567890123456789',
                'nickname' => 'Character Name [Twintania]',
                'run_id' => $this->activity->id,
            ]
            && $request->hasHeader('X-FullParty-Signature', $signature);
    });
    expect($this->character->fresh()->getRawOriginal())->toBe($character)
        ->and($this->slot->fresh()->getRawOriginal())->toBe($slot)
        ->and(DiscordUserIntegration::count())->toBe(0);
    $audit = AuditLog::where('action', 'group.activity.roster.discord_participant_synced')->sole();
    expect($audit->metadata['character_name'])->toBe('Character Name')
        ->and(json_encode($audit->toArray()))->not->toContain($this->input['discord_user_id']);
    $this->assertDatabaseCount('jobs', 0);
});

it('allows every published run status through the active window', function (string $status, int $minutes) {
    $this->activity->update(['status' => $status, 'starts_at' => now()->addMinutes($minutes)]);
    $this->actingAs($this->group->owner)->postJson($this->url, $this->input)->assertOk();
    Http::assertSentCount(1);
})->with([
    [Activity::STATUS_ASSIGNED, 30], [Activity::STATUS_UPCOMING, 0], [Activity::STATUS_ONGOING, -180],
]);

it('rejects unpublished, completed, cancelled, unscheduled and early runs', function (array $change) {
    if (isset($change['offset_seconds'])) {
        $change = ['starts_at' => now()->addSeconds($change['offset_seconds'])];
    }
    $this->activity->update($change);
    $this->actingAs($this->group->owner)->postJson($this->url, $this->input)->assertUnprocessable();
    Http::assertNothingSent();
})->with([
    'draft' => [['status' => Activity::STATUS_DRAFT]],
    'scheduled' => [['status' => Activity::STATUS_SCHEDULED]],
    'complete' => [['status' => Activity::STATUS_COMPLETE]],
    'cancelled' => [['status' => Activity::STATUS_CANCELLED]],
    'completed flag' => [['is_completed' => true]],
    'missing date' => [['starts_at' => null]],
    'one second too early' => [['offset_seconds' => 3601]],
]);

it('requires an installed active group Discord integration', function (string $state) {
    match ($state) {
        'removed' => $this->guild->update(['removed_at' => now()]),
        'not installed' => $this->guild->update(['guild_installed_at' => null]),
        'unlinked' => $this->guild->update(['group_id' => null]),
    };
    $this->actingAs($this->group->owner)->postJson($this->url, $this->input)->assertUnprocessable();
    Http::assertNothingSent();
})->with(['removed', 'not installed', 'unlinked']);

it('only permits group roster managers', function (string $role, int $status) {
    $user = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    $this->actingAs($user)->postJson($this->url, $this->input)->assertStatus($status);
    $status === 200 ? Http::assertSentCount(1) : Http::assertNothingSent();
})->with([['moderator', 200], ['admin', 200], ['member', 403]]);

it('rejects guests, nonmembers and mismatched route models', function () {
    $this->postJson($this->url, $this->input)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($this->url, $this->input)->assertNotFound();
    $other = Group::factory()->open()->create(['owner_id' => $this->group->owner_id]);
    $this->actingAs($this->group->owner)->postJson(route('groups.dashboard.activities.discord-participants.store', [
        'group' => $other, 'activity' => $this->activity, 'slot' => $this->slot,
    ]), $this->input)->assertNotFound();
    $otherActivity = Activity::factory()->create(['group_id' => $this->group->id]);
    $this->postJson(route('groups.dashboard.activities.discord-participants.store', [
        'group' => $this->group, 'activity' => $this->activity, 'slot' => $otherActivity->slots()->firstOrFail(),
    ]), $this->input)->assertNotFound();
    Http::assertNothingSent();
});

it('does not send for an empty slot or a stale assignment', function (bool $empty) {
    $this->slot->update(['assigned_character_id' => $empty ? null : Character::factory()->create()->id]);
    $this->actingAs($this->group->owner)->postJson($this->url, $this->input)->assertConflict();
    if ($empty) {
        $this->input['expected_slot_state_token'] = app(ActivitySlotStateTokenService::class)->generate($this->slot->fresh());
        $this->postJson($this->url, $this->input)->assertUnprocessable();
    }
    Http::assertNothingSent();
})->with([true, false]);

it('validates Discord IDs as numeric strings', function (mixed $id) {
    $this->actingAs($this->group->owner)->postJson($this->url, array_replace($this->input, ['discord_user_id' => $id]))
        ->assertUnprocessable()->assertJsonValidationErrors('discord_user_id');
    Http::assertNothingSent();
})->with([null, '', 'name', '<@234567890123456789>', '123', '023456789012345678', '234567890123456789012', 234567890123456789]);

it('surfaces bot HTTP and application failures without retaining echoed IDs', function (int $status, array $response) {
    $this->botResponse = $response;
    $this->botStatus = $status;
    $this->actingAs($this->group->owner)->postJson($this->url, $this->input)
        ->assertUnprocessable()->assertJsonValidationErrors('discord_user_id')
        ->assertDontSee($this->input['discord_user_id']);
    expect(json_encode($this->client->fresh()->toArray()))->not->toContain($this->input['discord_user_id'])
        ->and(AuditLog::where('action', 'group.activity.roster.discord_participant_synced')->exists())->toBeFalse()
        ->and(DiscordUserIntegration::count())->toBe(0);
})->with([
    [422, ['error' => 'Role missing for 234567890123456789']],
    [500, ['error' => 'Failed for 234567890123456789']],
    [200, ['success' => false, 'error' => 'Role missing for 234567890123456789']],
]);

it('handles unavailable or unauthorized bot clients without sending', function (array $change) {
    $this->client->update($change);
    $this->actingAs($this->group->owner)->postJson($this->url, $this->input)->assertUnprocessable();
    Http::assertNothingSent();
})->with([
    [['status' => IntegrationClient::STATUS_PAUSED]],
    [['allowed_events' => [IntegrationClient::EVENT_DISCORD_GUILD_RUN_STARTING_SOON]]],
    [['outbound_events_url' => null]],
]);

it('never flashes the temporary ID on form validation errors', function () {
    $this->actingAs($this->group->owner)->post($this->url, [
        'discord_user_id' => $this->input['discord_user_id'],
    ])->assertRedirect()->assertSessionMissing('_old_input.discord_user_id');
    Http::assertNothingSent();
});

it('exposes the opening time only on eligible management payloads', function () {
    $url = route('groups.dashboard.activities.management-data', ['group' => $this->group, 'activity' => $this->activity]);
    $this->actingAs($this->group->owner)->getJson($url)->assertOk()
        ->assertJsonPath('activity.discord_participant_sync_available_from', now()->toIso8601String());
    $this->activity->update(['status' => Activity::STATUS_COMPLETE]);
    $this->getJson($url)->assertOk()->assertJsonPath('activity.discord_participant_sync_available_from', null);
});
