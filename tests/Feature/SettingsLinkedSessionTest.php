<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

uses(RefreshDatabase::class);

function createLinkedSessionClient(): Client
{
    return app(ClientRepository::class)
        ->createDeviceAuthorizationGrantClient('FullParty XIV Plugin', false);
}

function createLinkedSessionToken(
    User $user,
    Client $client,
    string $tokenId,
    Carbon $accessExpiresAt,
    ?Carbon $refreshExpiresAt = null,
    bool $revoked = false,
): void {
    DB::table('oauth_access_tokens')->insert([
        'id' => $tokenId,
        'user_id' => $user->id,
        'client_id' => $client->id,
        'name' => null,
        'scopes' => json_encode(['xivplugin:read']),
        'revoked' => $revoked,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'expires_at' => $accessExpiresAt,
    ]);

    if ($refreshExpiresAt === null) {
        return;
    }

    DB::table('oauth_refresh_tokens')->insert([
        'id' => $tokenId.'-refresh',
        'access_token_id' => $tokenId,
        'revoked' => false,
        'expires_at' => $refreshExpiresAt,
    ]);
}

it('shows active linked app sessions on the settings page', function () {
    $user = User::factory()->create();
    $client = createLinkedSessionClient();

    createLinkedSessionToken(
        user: $user,
        client: $client,
        tokenId: 'settings-session-active',
        accessExpiresAt: now()->subHour(),
        refreshExpiresAt: now()->addDays(30),
    );

    createLinkedSessionToken(
        user: $user,
        client: $client,
        tokenId: 'settings-session-expired',
        accessExpiresAt: now()->subHours(2),
        refreshExpiresAt: now()->subHour(),
    );

    $this->actingAs($user)
        ->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Settings/Index')
            ->has('activeLinkedSessions', 1)
            ->where('activeLinkedSessions.0.id', 'settings-session-active')
            ->where('activeLinkedSessions.0.client_name', 'FullParty XIV Plugin')
            ->where('activeLinkedSessions.0.scopes.0', 'xivplugin:read')
        );
});

it('revokes a linked app session and its refresh token', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $client = createLinkedSessionClient();

    createLinkedSessionToken(
        user: $user,
        client: $client,
        tokenId: 'settings-session-revoke',
        accessExpiresAt: now()->addHour(),
        refreshExpiresAt: now()->addDays(30),
    );

    createLinkedSessionToken(
        user: $otherUser,
        client: $client,
        tokenId: 'settings-session-other',
        accessExpiresAt: now()->addHour(),
        refreshExpiresAt: now()->addDays(30),
    );

    $this->actingAs($user)
        ->from(route('settings'))
        ->delete(route('settings.linked-sessions.destroy', 'settings-session-revoke'))
        ->assertRedirect(route('settings'))
        ->assertSessionHas('success', fn (array $success) => in_array('linked_session_revoked', $success, true));

    $this->assertDatabaseHas('oauth_access_tokens', [
        'id' => 'settings-session-revoke',
        'revoked' => true,
    ]);

    $this->assertDatabaseHas('oauth_refresh_tokens', [
        'access_token_id' => 'settings-session-revoke',
        'revoked' => true,
    ]);

    $this->assertDatabaseHas('oauth_access_tokens', [
        'id' => 'settings-session-other',
        'revoked' => false,
    ]);
});

it('does not revoke another users linked app session', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $client = createLinkedSessionClient();

    createLinkedSessionToken(
        user: $otherUser,
        client: $client,
        tokenId: 'settings-session-owned-elsewhere',
        accessExpiresAt: now()->addHour(),
        refreshExpiresAt: now()->addDays(30),
    );

    $this->actingAs($user)
        ->delete(route('settings.linked-sessions.destroy', 'settings-session-owned-elsewhere'))
        ->assertNotFound();

    $this->assertDatabaseHas('oauth_access_tokens', [
        'id' => 'settings-session-owned-elsewhere',
        'revoked' => false,
    ]);
});
