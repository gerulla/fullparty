<?php

use App\DTOs\LodestoneCharacterData;
use App\Models\ActivityApplication;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\User;
use App\Services\Lodestone\LodestoneScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance('env', 'local');
    Queue::fake();
    Http::preventStrayRequests();
    Cache::flush();
    $this->user = User::factory()->create(['name' => 'Receiving Account']);
    $this->owner = User::factory()->create(['name' => 'Original Account']);
    $this->character = Character::factory()->for($this->owner)->unverified()->create([
        'lodestone_id' => '12345678', 'token' => 'FP-PRIVATE-OWNER-CODE', 'expires_at' => now()->addDay(),
    ]);
    $this->actingAs($this->user);
    $this->withSession(['_token' => 'transfer-test'])->withHeader('X-CSRF-TOKEN', 'transfer-test');
});

function transferProvider(User $user, bool $verified = true): void
{
    $user->socialAccounts()->create([
        'provider' => 'xivauth', 'provider_user_id' => 'xiv-'.$user->id,
        'access_token' => 'test-token', 'expires_at' => now()->addHour(),
    ]);
    Http::fake(['xivauth.net/api/v1/characters' => Http::response([[
        'lodestone_id' => '12345678', 'name' => 'Test Adventurer', 'home_world' => 'Lich',
        'data_center' => 'Light', 'avatar_url' => null, 'verified_at' => $verified ? '2026-01-01T00:00:00Z' : null,
    ]])]);
}

function transferProfile(string $code, string $id = '12345678'): LodestoneCharacterData
{
    return new LodestoneCharacterData(
        lodestoneId: $id, profileUrl: "https://na.finalfantasyxiv.com/lodestone/character/{$id}/",
        classJobUrl: "https://na.finalfantasyxiv.com/lodestone/character/{$id}/class_job/",
        name: 'Test Adventurer', world: 'Lich', dataCenter: 'Light', avatarUrl: 'https://example.test/avatar.png', bio: "Hello!\n{$code}\n",
    );
}

function beginTransferClaim($test): string
{
    $test->postJson(route('characters.onboarding.store'), ['method' => 'lodestone', 'lodestone_id' => '12345678'])
        ->assertOk()->assertJsonPath('outcome', 'claim_required');

    return $test->postJson(route('characters.claim.code', $test->character))->assertOk()
        ->assertJsonPath('context', 'transfer')->json('code');
}

it('transfers unclaimed characters with fresh XIVAuth proof and records both accounts', function (bool $verified) {
    $this->character->update(['verified_at' => $verified ? now()->subMonth() : null]);
    transferProvider($this->user);
    $payload = ['method' => 'xivauth', 'lodestone_id' => '12345678'];
    $this->postJson(route('characters.onboarding.store'), $payload)->assertOk()
        ->assertJsonPath('outcome', 'added')->assertJsonPath('transferred', true)
        ->assertJsonPath('character.id', $this->character->id)->assertJsonPath('character.status', 'claimed')
        ->assertJsonMissingPath('character.user_id')->assertJsonMissingPath('character.token');
    $character = $this->character->fresh();
    expect($character->user_id)->toBe($this->user->id)->and($character->claimed_at)->not->toBeNull()
        ->and($character->token)->toBeNull()->and((bool) $character->is_primary)->toBeTrue();
    $audit = AuditLog::where('action', 'character.transferred')->sole();
    expect($audit->metadata)->toMatchArray([
        'from_user_id' => $this->owner->id, 'from_user_name' => 'Original Account',
        'to_user_id' => $this->user->id, 'to_user_name' => 'Receiving Account',
        'previous_status' => $verified ? 'verified' : 'unverified', 'verification_method' => 'xivauth',
    ]);
    $this->assertDatabaseHas('notification_events', ['type' => 'characters.unclaimed']);
    $this->assertDatabaseHas('notification_events', ['type' => 'characters.added']);
    $this->postJson(route('characters.onboarding.store'), $payload)->assertOk()->assertJsonPath('transferred', false);
    expect(AuditLog::where('action', 'character.transferred')->count())->toBe(1);
    Http::assertSentCount(1);
})->with([true, false]);

it('never transfers through XIVAuth without fresh verified provider data', function () {
    transferProvider($this->user, verified: false);
    $this->postJson(route('characters.onboarding.store'), [
        'method' => 'xivauth', 'lodestone_id' => '12345678', 'verified_at' => now()->toIso8601String(),
    ])->assertUnprocessable()->assertJsonPath('errors.onboarding.0', 'xivauth_character_unavailable');
    expect($this->character->fresh()->user_id)->toBe($this->owner->id);
    $this->get(route('characters.claim', $this->character))->assertNotFound();
});

it('blocks claimed characters for either method and provides the Discord help screen without a report', function (string $method) {
    $this->character->update(['verified_at' => now(), 'claimed_at' => now()]);
    if ($method === 'xivauth') {
        transferProvider($this->user);
    }
    $this->postJson(route('characters.onboarding.store'), ['method' => $method, 'lodestone_id' => '12345678'])
        ->assertOk()->assertJsonPath('outcome', 'already_claimed')->assertJsonPath('transferred', false);
    $this->get(route('characters.claim', $this->character))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('context', 'already_claimed')->where('character.status', 'claimed')
        ->missing('character.user_id')->missing('character.token'));
    $this->postJson(route('characters.claim.code', $this->character))->assertConflict()
        ->assertJsonPath('outcome', 'already_claimed')->assertJsonMissingPath('code');
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-PRIVATE-OWNER-CODE'])
        ->assertConflict()->assertJsonPath('outcome', 'already_claimed');
    expect($this->character->fresh()->user_id)->toBe($this->owner->id);
    $this->assertDatabaseCount('notification_events', 0);
    expect(AuditLog::where('action', 'character.transferred')->exists())->toBeFalse();
})->with(['xivauth', 'lodestone']);

it('keeps the character with its owner until Lodestone proof succeeds and shows the correct context', function (bool $verified) {
    $this->character->update(['verified_at' => $verified ? now()->subMonth() : null]);
    $code = beginTransferClaim($this);
    $this->get(route('characters.claim', $this->character))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('context', 'transfer')->where('character.status', $verified ? 'verified' : 'unverified')
        ->missing('character.user_id')->missing('character.token'));
    expect($this->character->fresh()->user_id)->toBe($this->owner->id)
        ->and($this->character->fresh()->token)->toBe('FP-PRIVATE-OWNER-CODE');
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->with('12345678', true)
        ->andReturn(transferProfile($code));
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => $code])
        ->assertOk()->assertJsonPath('transferred', true)->assertJsonPath('character.status', 'claimed');
    expect($this->character->fresh()->user_id)->toBe($this->user->id);
    $audit = AuditLog::where('action', 'character.transferred')->sole();
    expect($audit->metadata)->toMatchArray([
        'verification_method' => 'lodestone_token', 'from_user_id' => $this->owner->id, 'to_user_id' => $this->user->id,
    ]);
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => $code])->assertOk();
    expect(AuditLog::where('action', 'character.transferred')->count())->toBe(1);
    $this->assertDatabaseCount('notification_events', 2);
})->with([true, false]);

it('requires an account-scoped grant for foreign claim routes and keeps refresh owner-only', function () {
    $this->get(route('characters.claim', $this->character))->assertNotFound();
    $this->postJson(route('characters.claim.code', $this->character))->assertNotFound();
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-PRIVATE-OWNER-CODE'])->assertNotFound();
    beginTransferClaim($this);
    $this->postJson(route('characters.onboarding.refresh', $this->character))->assertNotFound();
    $this->actingAs(User::factory()->create());
    $this->get(route('characters.claim', $this->character))->assertNotFound();
    $this->postJson(route('characters.claim.code', $this->character))->assertNotFound();
    Http::assertNothingSent();
});

it('isolates transfer codes per requester and never exposes or replaces the owner code', function () {
    $firstCode = beginTransferClaim($this);
    $this->postJson(route('characters.claim.code', $this->character))->assertOk()->assertJsonPath('code', $firstCode);
    $secondUser = User::factory()->create();
    $this->actingAs($secondUser);
    $secondCode = beginTransferClaim($this);
    expect($secondCode)->not->toBe($firstCode)->not->toBe('FP-PRIVATE-OWNER-CODE');
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => $firstCode])
        ->assertUnprocessable()->assertJsonPath('errors.claim.0', 'code_changed');
    $this->actingAs($this->owner)->postJson(route('characters.claim.code', $this->character))->assertOk()
        ->assertJsonPath('code', 'FP-PRIVATE-OWNER-CODE');
    Http::assertNothingSent();
});

it('rejects expired transfer proof before calling Lodestone', function () {
    $code = beginTransferClaim($this);
    $this->travel(25)->hours();
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => $code])
        ->assertUnprocessable()->assertJsonPath('errors.claim.0', 'expired_code');
    expect($this->character->fresh()->user_id)->toBe($this->owner->id);
    $nextCode = $this->postJson(route('characters.claim.code', $this->character))->assertOk()->json('code');
    expect($nextCode)->not->toBe($code);
    Http::assertNothingSent();
});

it('requires the exact transfer code on the matching profile', function (string $failure) {
    $code = beginTransferClaim($this);
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()
        ->andReturn(transferProfile($failure === 'substring' ? $code.'-WRONG' : $code, $failure === 'wrong_character' ? '87654321' : '12345678'));
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => $code])
        ->assertUnprocessable()->assertJsonPath('errors.claim.0', 'code_not_found');
    expect($this->character->fresh()->user_id)->toBe($this->owner->id);
})->with(['substring', 'wrong_character']);

it('rechecks ownership and claimed status after the external proof request', function (bool $claimed) {
    $code = beginTransferClaim($this);
    $newOwner = User::factory()->create();
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andReturnUsing(function () use ($code, $newOwner, $claimed) {
        $this->character->update($claimed ? ['claimed_at' => now(), 'verified_at' => now()] : ['user_id' => $newOwner->id]);

        return transferProfile($code);
    });
    $response = $this->postJson(route('characters.claim.verify', $this->character), ['code' => $code]);
    if ($claimed) {
        $response->assertConflict()->assertJsonPath('outcome', 'already_claimed');
    } else {
        $response->assertUnprocessable()->assertJsonPath('errors.claim.0', 'ownership_changed');
    }
    expect($this->character->fresh()->user_id)->toBe($claimed ? $this->owner->id : $newOwner->id);
    expect(AuditLog::where('action', 'character.transferred')->exists())->toBeFalse();
})->with([true, false]);

it('enforces recipient character capacity during transfers without changing the owner', function (string $method) {
    config(['quotas.mode' => 'enforce', 'quotas.limits' => array_replace(config('quotas.limits'), ['characters.total' => 1])]);
    Character::factory()->for($this->user)->create();
    if ($method === 'xivauth') {
        transferProvider($this->user);
        $response = $this->postJson(route('characters.onboarding.store'), ['method' => $method, 'lodestone_id' => '12345678']);
    } else {
        $code = beginTransferClaim($this);
        $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andReturn(transferProfile($code));
        $response = $this->postJson(route('characters.claim.verify', $this->character), ['code' => $code]);
    }
    $response->assertUnprocessable()->assertJsonValidationErrors('quota');
    expect($this->character->fresh()->user_id)->toBe($this->owner->id);
    expect(AuditLog::where('action', 'character.transferred')->exists())->toBeFalse();
})->with(['xivauth', 'lodestone']);

it('preserves primary selections and keeps account applications with their original account', function () {
    $this->character->update(['is_primary' => true]);
    $replacement = Character::factory()->for($this->owner)->create(['is_primary' => false]);
    $primary = Character::factory()->for($this->user)->primary()->create();
    $oldApplication = ActivityApplication::factory()->create([
        'user_id' => $this->owner->id, 'selected_character_id' => $this->character->id, 'applicant_lodestone_id' => '12345678',
    ]);
    $ownApplication = ActivityApplication::factory()->create(['user_id' => $this->user->id]);
    $guest = ActivityApplication::factory()->guest()->create([
        'activity_id' => $ownApplication->activity_id, 'selected_character_id' => $this->character->id, 'applicant_lodestone_id' => '12345678',
    ]);
    transferProvider($this->user);
    $this->postJson(route('characters.onboarding.store'), ['method' => 'xivauth', 'lodestone_id' => '12345678'])->assertOk();
    expect((bool) $replacement->fresh()->is_primary)->toBeTrue()->and((bool) $primary->fresh()->is_primary)->toBeTrue()
        ->and((bool) $this->character->fresh()->is_primary)->toBeFalse()
        ->and($oldApplication->fresh()->user_id)->toBe($this->owner->id)
        ->and($guest->fresh()->user_id)->toBeNull()
        ->and(ActivityApplication::where('activity_id', $ownApplication->activity_id)->where('user_id', $this->user->id)->count())->toBe(1);
});

it('shows the previous and new account names and IDs in the admin audit feed', function () {
    transferProvider($this->user);
    $this->postJson(route('characters.onboarding.store'), ['method' => 'xivauth', 'lodestone_id' => '12345678'])->assertOk();
    $url = route('admin.audit-log', ['locale' => 'en', 'action' => 'character.transferred']);
    $this->getJson($url)->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson($url)
        ->assertOk()->assertJsonCount(1, 'auditLogs')->assertJsonPath('auditLogs.0.details', fn ($details) => in_array('Previous account ID: '.$this->owner->id, $details, true)
            && in_array('Previous account name: Original Account', $details, true)
            && in_array('New account ID: '.$this->user->id, $details, true)
            && in_array('New account name: Receiving Account', $details, true));
});

it('does not overwrite a claim made while XIVAuth ownership proof is loading', function () {
    transferProvider($this->user);
    Http::fake(['xivauth.net/api/v1/characters' => function () {
        $this->character->update(['claimed_at' => now(), 'verified_at' => now()]);

        return Http::response([[
            'lodestone_id' => '12345678', 'name' => 'Test Adventurer', 'home_world' => 'Lich',
            'data_center' => 'Light', 'verified_at' => '2026-01-01T00:00:00Z',
        ]]);
    }]);
    $this->postJson(route('characters.onboarding.store'), ['method' => 'xivauth', 'lodestone_id' => '12345678'])
        ->assertOk()->assertJsonPath('outcome', 'already_claimed')->assertJsonPath('transferred', false);
    expect($this->character->fresh()->user_id)->toBe($this->owner->id);
    expect(AuditLog::where('action', 'character.transferred')->exists())->toBeFalse();
});
