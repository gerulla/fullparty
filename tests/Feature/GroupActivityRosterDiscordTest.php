<?php

use App\Models\Activity;
use App\Models\Character;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->group = Group::factory()->open()->create();
    $this->activity = Activity::factory()->create(['group_id' => $this->group->id]);
    $this->slot = $this->activity->slots()->firstOrFail();
    $this->applicant = User::factory()->create();
    $this->character = Character::factory()->create(['user_id' => $this->applicant->id]);
    $this->slot->update(['assigned_character_id' => $this->character->id]);
    $this->url = route('groups.dashboard.activities.roster-discord-ids', [
        'group' => $this->group,
        'activity' => $this->activity,
    ]);
});

it('returns only assigned users Discord IDs as strings for roster managers', function () {
    DiscordUserIntegration::create([
        'user_id' => $this->applicant->id,
        'discord_user_id' => '123456789012345678',
    ]);
    DiscordUserIntegration::create([
        'user_id' => User::factory()->create()->id,
        'discord_user_id' => '987654321098765432',
    ]);

    $response = $this->actingAs($this->group->owner)->getJson($this->url)
        ->assertOk()->assertExactJson([
            'discord_user_ids' => [$this->applicant->id => '123456789012345678'],
        ]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // The shared serializer also serves public overviews; contact IDs stay out of it.
    $this->slot->load(['activity.activityTypeVersion', 'assignedCharacter', 'compositionHints', 'fieldValues', 'assignments']);
    expect(json_encode(app(ActivitySlotSerializer::class)->serialize($this->slot)))
        ->not->toContain('123456789012345678');
});

it('supports Discord social links without requiring an installed user app', function () {
    $this->applicant->socialAccounts()->create([
        'provider' => 'discord',
        'provider_user_id' => '123456789012345678',
        'access_token' => 'must-not-be-returned',
        'provider_email' => 'private@example.com',
    ]);
    DiscordUserIntegration::create([
        'user_id' => $this->applicant->id,
        'discord_user_id' => '987654321098765432',
        'revoked_at' => now(),
    ]);

    $this->actingAs($this->group->owner)->getJson($this->url)
        ->assertOk()->assertExactJson([
            'discord_user_ids' => [$this->applicant->id => '123456789012345678'],
        ]);
});

it('omits unlinked users and revoked Discord integrations', function (string $link) {
    if ($link === 'revoked') {
        DiscordUserIntegration::create([
            'user_id' => $this->applicant->id,
            'discord_user_id' => '123456789012345678',
            'revoked_at' => now(),
        ]);
    } elseif ($link === 'other_provider') {
        $this->applicant->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => '123456789012345678',
        ]);
    }

    $response = $this->actingAs($this->group->owner)->getJson($this->url)->assertOk();

    expect($response->json('discord_user_ids'))->toBe([])
        ->and(json_decode($response->getContent())->discord_user_ids)->toBeInstanceOf(stdClass::class);
})->with(['none', 'revoked', 'other_provider']);

it('refreshes the IDs when a slot is reassigned or cleared', function () {
    DiscordUserIntegration::create([
        'user_id' => $this->applicant->id,
        'discord_user_id' => '123456789012345678',
    ]);
    $replacement = Character::factory()->create();
    $replacement->user->socialAccounts()->create([
        'provider' => 'discord',
        'provider_user_id' => '987654321098765432',
    ]);

    $this->slot->update(['assigned_character_id' => $replacement->id]);
    $this->actingAs($this->group->owner)->getJson($this->url)
        ->assertOk()->assertExactJson([
            'discord_user_ids' => [$replacement->user_id => '987654321098765432'],
        ]);

    $this->slot->update(['assigned_character_id' => Character::factory()->provisional()->create()->id]);
    $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'discord_user_ids');

    $this->slot->update(['assigned_character_id' => null]);
    $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'discord_user_ids');
});

it('allows moderators and admins but denies ordinary members', function (string $role, int $status) {
    $viewer = User::factory()->create();
    $this->group->memberships()->create(['user_id' => $viewer->id, 'role' => $role]);

    $this->actingAs($viewer)->getJson($this->url)->assertStatus($status);
})->with(['moderator' => ['moderator', 200], 'admin' => ['admin', 200], 'member' => ['member', 403]]);

it('requires authentication and rejects a mismatched group', function () {
    $this->getJson($this->url)->assertUnauthorized();

    $otherGroup = Group::factory()->open()->create(['owner_id' => $this->group->owner_id]);
    $this->actingAs($this->group->owner)->getJson(route('groups.dashboard.activities.roster-discord-ids', [
        'group' => $otherGroup,
        'activity' => $this->activity,
    ]))->assertNotFound();
});
