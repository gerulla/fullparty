<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Character;
use App\Models\CharacterTransfer;
use App\Models\User;
use App\Services\AccountFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->instance('env', 'local');
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->previousOwner = User::factory()->create();
    $this->character = Character::factory()->for($this->user)->create();
    $this->transfer = CharacterTransfer::create([
        'character_id' => $this->character->id,
        'from_user_id' => $this->previousOwner->id,
        'to_user_id' => $this->user->id,
        'method' => 'xivauth',
    ]);
});

it('requires authentication and only exposes unresolved transfers belonging to the current owner', function () {
    $this->getJson(route('account.flags.index'))->assertUnauthorized();
    $this->actingAs($this->previousOwner)->getJson(route('account.flags.index'))->assertExactJson(['flags' => []]);

    $response = $this->actingAs($this->user)->getJson(route('account.flags.index'))->assertOk()
        ->assertJsonCount(1, 'flags')
        ->assertJsonPath('flags.0.id', 'character-transfer:'.$this->transfer->id)
        ->assertJsonPath('flags.0.severity', 'warning')
        ->assertJsonPath('flags.0.parameters.character', $this->character->name)
        ->assertJsonPath('flags.0.url', route('characters.claim', $this->character, absolute: false));
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect(array_keys($response->json('flags.0')))->toBe([
        'id', 'severity', 'title_key', 'description_key', 'parameters', 'url',
    ]);

    $this->character->update(['user_id' => $this->previousOwner->id]);
    $this->getJson(route('account.flags.index'))->assertExactJson(['flags' => []]);
});

it('persists after viewing and reloading but disappears when choices are saved', function () {
    $this->actingAs($this->user)->withSession(['_token' => 'account-flag'])
        ->withHeader('X-CSRF-TOKEN', 'account-flag');
    $this->get(route('characters.claim', $this->character))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('account_flags', 1)
            ->where('transfer.id', $this->transfer->id)->where('transfer.method', 'xivauth'));
    $this->getJson(route('account.flags.index'))->assertJsonCount(1, 'flags');
    expect($this->transfer->fresh()->completed_at)->toBeNull();

    $this->postJson(route('characters.transfer.complete', $this->transfer), [
        'history' => 'discard', 'upcoming' => 'discard',
    ])->assertOk();
    $this->getJson(route('account.flags.index'))->assertExactJson(['flags' => []]);
    $this->get(route('characters.claim', $this->character))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('account_flags', []));
});

it('shares current flags even on partial Inertia reloads', function () {
    $this->actingAs($this->user)->get(route('characters.claim', $this->character), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
        'X-Inertia-Partial-Component' => 'V2/Characters/Claim',
        'X-Inertia-Partial-Data' => 'character',
    ])->assertOk()->assertJsonCount(1, 'props.account_flags');
});

it('does not show completed, superseded or deleted character issues', function () {
    $latest = $this->transfer->replicate();
    $latest->save();
    $flags = app(AccountFlagService::class)->forUser($this->user);
    expect($flags)->toHaveCount(1)->and($flags[0]['id'])->toBe('character-transfer:'.$latest->id);
    $this->transfer->update(['completed_at' => now()]);
    $latest->update(['completed_at' => now()]);
    expect(app(AccountFlagService::class)->forUser($this->user))->toBe([]);
    $latest->update(['completed_at' => null]);
    $this->character->delete();
    expect(app(AccountFlagService::class)->forUser($this->user))->toBe([]);
});

it('does not offer local-only resolution routes in production', function () {
    app()->instance('env', 'production');
    expect(app(AccountFlagService::class)->forUser($this->user))->toBe([]);
});
