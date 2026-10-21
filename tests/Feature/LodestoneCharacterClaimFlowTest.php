<?php

use App\DTOs\LodestoneCharacterData;
use App\Exceptions\LodestoneFetchException;
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
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->withSession(['_token' => 'claim-test-csrf'])->withHeader('X-CSRF-TOKEN', 'claim-test-csrf');
    $this->character = Character::factory()->for($this->user)->unverified()->create([
        'lodestone_id' => '12345678', 'token' => 'FP-TESTCLAIM', 'expires_at' => now()->addDay(),
    ]);
});

function claimFlowProfile(string $bio = 'Hello!\nFP-TESTCLAIM', string $id = '12345678'): LodestoneCharacterData
{
    return new LodestoneCharacterData(
        lodestoneId: $id, profileUrl: "https://na.finalfantasyxiv.com/lodestone/character/{$id}/",
        classJobUrl: "https://na.finalfantasyxiv.com/lodestone/character/{$id}/class_job/",
        name: 'Test Adventurer', world: 'Lich', dataCenter: 'Light', avatarUrl: 'https://example.test/avatar.png', bio: $bio,
    );
}

it('renders the owned character without issuing a token on GET or exposing raw model data', function () {
    $this->character->update(['token' => null, 'expires_at' => null]);
    $this->get(route('characters.claim', $this->character))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('V2/Characters/Claim')
            ->where('character.id', $this->character->id)->where('character.status', 'unverified')
            ->missing('character.token')->missing('character.user_id'));
    expect($this->character->fresh()->token)->toBeNull();
});

it('creates expiring codes and reuses them across reloads', function () {
    $this->character->update(['token' => null, 'expires_at' => null]);
    $response = $this->postJson(route('characters.claim.code', $this->character))->assertOk();
    $code = $response->json('code');
    expect($code)->toMatch('/^FP-[A-Z0-9]{20}$/')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->postJson(route('characters.claim.code', $this->character))->assertOk()->assertJsonPath('code', $code);
    expect($this->character->fresh()->expires_at->isFuture())->toBeTrue()
        ->and($this->character->fresh()->claimed_at)->toBeNull();
});

it('renews expired codes or codes without an expiry', function (bool $missingExpiry) {
    $this->character->update(['expires_at' => $missingExpiry ? null : now()->subMinute()]);
    $response = $this->postJson(route('characters.claim.code', $this->character))->assertOk();
    expect($response->json('code'))->not->toBe('FP-TESTCLAIM');
})->with([true, false]);

it('claims unverified and participation-verified characters without losing their history', function (bool $verified) {
    $verifiedAt = $verified ? now()->subMonth()->startOfSecond() : null;
    $this->character->update(['verified_at' => $verifiedAt, 'is_primary' => true]);
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->with('12345678', true)
        ->andReturn(claimFlowProfile("Hello!\nFP-TESTCLAIM\n"));
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-TESTCLAIM'])
        ->assertOk()->assertJsonPath('character.status', 'claimed')->assertJsonMissingPath('character.token');
    $character = $this->character->fresh();
    expect($character->claimed_at)->not->toBeNull()->and($character->verified_at)->not->toBeNull()
        ->and((bool) $character->is_primary)->toBeTrue()->and($character->token)->toBeNull()->and($character->expires_at)->toBeNull();
    if ($verifiedAt) {
        expect($character->verified_at->equalTo($verifiedAt))->toBeTrue();
    }
    $this->assertDatabaseCount('notification_events', 1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'character.verified', 'scope_id' => $character->id]);
    $this->postJson(route('characters.claim.verify', $character), ['code' => 'FP-TESTCLAIM'])
        ->assertOk()->assertJsonPath('character.status', 'claimed');
    $this->postJson(route('characters.claim.code', $character))->assertOk()->assertJsonPath('code', null);
    $this->assertDatabaseCount('notification_events', 1);
    expect(AuditLog::where('action', 'character.verified')->count())->toBe(1);
})->with([true, false]);

it('preserves an existing primary or selects the first claimed character when none exists', function (bool $hasPrimary) {
    if ($hasPrimary) {
        Character::factory()->for($this->user)->primary()->create();
    }
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andReturn(claimFlowProfile('FP-TESTCLAIM'));
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-TESTCLAIM'])->assertOk();
    expect((bool) $this->character->fresh()->is_primary)->toBe(! $hasPrimary)
        ->and(Character::where('user_id', $this->user->id)->where('is_primary', true)->count())->toBe(1);
})->with([true, false]);

it('rejects expired or stale codes before accessing Lodestone', function (string $reason) {
    if ($reason === 'expired_code') {
        $this->character->update(['expires_at' => now()->subSecond()]);
    }
    $this->mock(LodestoneScraper::class)->shouldNotReceive('scrapeProfile');
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => $reason === 'expired_code' ? 'FP-TESTCLAIM' : 'FP-OTHER'])
        ->assertUnprocessable()->assertJsonPath('errors.claim.0', $reason);
    expect($this->character->fresh()->claimed_at)->toBeNull();
})->with(['expired_code', 'code_changed']);

it('requires the exact code on the correct public profile', function (string $bio, string $id) {
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andReturn(claimFlowProfile($bio, $id));
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-TESTCLAIM'])
        ->assertUnprocessable()->assertJsonPath('errors.claim.0', 'code_not_found');
    expect($this->character->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('notification_events', 0);
})->with([
    'absent' => ['', '12345678'], 'partial' => ['FP-TESTCLAIMMORE', '12345678'],
    'prefixed' => ['ANOTHER-FP-TESTCLAIM', '12345678'], 'wrong profile' => ['FP-TESTCLAIM', '87654321'],
]);

it('handles Lodestone outages without changing character state or leaking diagnostics', function () {
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andThrow(new LodestoneFetchException('private upstream diagnostic'));
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-TESTCLAIM'])
        ->assertUnprocessable()->assertJsonPath('errors.claim.0', 'lodestone_unavailable')->assertDontSee('private upstream diagnostic');
    expect($this->character->fresh()->token)->toBe('FP-TESTCLAIM')->and($this->character->fresh()->claimed_at)->toBeNull();
});

it('rechecks ownership and proof after the external request', function (string $change) {
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andReturnUsing(function () use ($change) {
        $this->character->update(match ($change) {
            'owner' => ['user_id' => User::factory()->create()->id],
            'code' => ['token' => 'FP-REPLACED'],
            'expiry' => ['expires_at' => now()->subMinute()],
        });

        return claimFlowProfile('FP-TESTCLAIM');
    });
    $response = $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-TESTCLAIM']);
    $change === 'owner' ? $response->assertNotFound() : $response->assertUnprocessable();
    expect($this->character->fresh()->claimed_at)->toBeNull();
    $this->assertDatabaseCount('notification_events', 0);
})->with(['owner', 'code', 'expiry']);

it('links guest applications without giving an account two applications in the same run', function () {
    $matching = ActivityApplication::factory()->guest()->create(['applicant_lodestone_id' => '12345678']);
    $existing = ActivityApplication::factory()->create(['user_id' => $this->user->id]);
    $conflict = ActivityApplication::factory()->guest()->create(['activity_id' => $existing->activity_id, 'applicant_lodestone_id' => '12345678']);
    $this->mock(LodestoneScraper::class)->shouldReceive('scrapeProfile')->once()->andReturn(claimFlowProfile('FP-TESTCLAIM'));
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-TESTCLAIM'])->assertOk();
    expect($matching->fresh()->user_id)->toBe($this->user->id)
        ->and($matching->fresh()->selected_character_id)->toBe($this->character->id)
        ->and($matching->fresh()->guest_access_token)->toBeNull()
        ->and($conflict->fresh()->user_id)->toBeNull();
});

it('does not expose or modify another accounts character', function (string $endpoint) {
    $other = Character::factory()->unverified()->create();
    $url = route($endpoint, $other);
    ($endpoint === 'characters.claim' ? $this->get($url) : $this->postJson($url, ['code' => $other->token]))->assertNotFound();
    expect($other->fresh()->token)->toBe($other->token)->and($other->fresh()->claimed_at)->toBeNull();
    Http::assertNothingSent();
})->with(['characters.claim', 'characters.claim.code', 'characters.claim.verify']);

it('keeps claim preview endpoints unavailable in production', function (string $endpoint) {
    app()->instance('env', 'production');
    $url = route($endpoint, $this->character);
    ($endpoint === 'characters.claim' ? $this->get($url) : $this->postJson($url, ['code' => 'FP-TESTCLAIM']))->assertNotFound();
})->with(['characters.claim', 'characters.claim.code', 'characters.claim.verify']);

it('requires authentication and CSRF protection', function () {
    $this->withHeader('X-CSRF-TOKEN', 'incorrect')->postJson(route('characters.claim.code', $this->character))->assertStatus(419);
    auth()->logout();
    $this->get(route('characters.claim', $this->character))->assertRedirect(route('login'));
});

it('rate limits claim checks', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson(route('characters.claim.code', $this->character))->assertOk();
    }
    $this->postJson(route('characters.claim.verify', $this->character), ['code' => 'FP-TESTCLAIM'])->assertTooManyRequests();
    Http::assertNothingSent();
});

it('does not let the legacy endpoint bypass the submitted-code check', function () {
    $this->mock(LodestoneScraper::class)->shouldNotReceive('scrapeProfile');
    $this->post(route('characters.verify'), ['character_id' => $this->character->id, 'token' => 'FP-STALE'])
        ->assertRedirect()->assertSessionHasErrors(['error' => 'invalid_token']);
    expect($this->character->fresh()->claimed_at)->toBeNull();
});
