<?php

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('only presents the signed-in users characters with their distinct ownership states and stored tower progress', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $claimed = Character::factory()->for($user)->primary()->create(['claimed_at' => now()]);
    $verified = Character::factory()->for($user)->create(['claimed_at' => null, 'verified_at' => now()]);
    $unverified = Character::factory()->for($user)->unverified()->create(['claimed_at' => null]);
    Character::factory()->create();

    $claimed->occultProgress()->create([
        'knowledge_level' => 60,
        'demon_tablet_kills' => 8,
        'dead_stars_kills' => 8,
        'marble_dragon_kills' => 8,
        'magitaur_kills' => 8,
        'two_headed_aevis_kills' => 9,
        'sword_dancer_kills' => 4,
        'necrophobia_kills' => 0,
        'index_kills' => 0,
    ]);

    $this->actingAs($user)->get(route('account.characters', ['locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Account/MyCharacters')
            ->has('characters', 3)
            ->where('characters', function ($characters) use ($claimed, $verified, $unverified) {
                $byId = collect($characters)->keyBy('id');

                expect($byId[$claimed->id]['claimed_at'])->not->toBeNull()
                    ->and($byId[$verified->id]['claimed_at'])->toBeNull()
                    ->and($byId[$verified->id]['verified_at'])->not->toBeNull()
                    ->and($byId[$unverified->id]['claimed_at'])->toBeNull()
                    ->and($byId[$unverified->id]['verified_at'])->toBeNull()
                    ->and($byId[$claimed->id]['occult']['knowledge_level'])->toBe(60)
                    ->and($byId[$claimed->id]['occult']['blood_progress']['clears'])->toBe(8)
                    ->and($byId[$claimed->id]['occult']['magic_progress']['clears'])->toBe(0)
                    ->and($byId[$claimed->id]['occult']['magic_progress']['bosses'])->toHaveCount(4)
                    ->and(collect($byId[$claimed->id]['occult']['magic_progress']['bosses'])->where('kills', '>', 0))->toHaveCount(2)
                    ->and($byId[$unverified->id]['occult']['blood_progress']['bosses'])->toHaveCount(4)
                    ->and($byId[$unverified->id]['occult']['magic_progress']['bosses'])->toHaveCount(4);

                return true;
            }));

    Http::assertNothingSent();
});

it('requires sign-in to view the character overview', function () {
    $this->get(route('account.characters', ['locale' => 'en']))
        ->assertRedirect(route('login', ['locale' => 'en']));
});

it('keeps character deep links scoped to the signed-in account', function (bool $linkToOtherUser) {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $mine = Character::factory()->for($user)->create();
    $other = Character::factory()->create();

    $this->actingAs($user)->get(route('account.characters', [
        'locale' => 'en',
        'character' => $linkToOtherUser ? $other->id : $mine->id,
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard/Account/MyCharacters')
        ->has('characters', 1)
        ->where('characters.0.id', $mine->id));

    Http::assertNothingSent();
})->with([false, true]);
