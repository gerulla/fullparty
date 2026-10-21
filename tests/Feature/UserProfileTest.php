<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\ManagedImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the participant homepage separately from the users profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['locale' => 'de']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('V2/Home'));
});

it('opens an owner-only profile with the existing deferred activity sections', function () {
    $user = User::factory()->create();
    $user->homeProfile()->create(['description' => 'Ready for the next run.']);

    $this->actingAs($user)
        ->get(route('profile.show', ['user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Show')
            ->where('profile.id', $user->id)
            ->where('profile.home_profile.description', 'Ready for the next run.')
            ->loadDeferredProps('home-banner-details', fn (Assert $page) => $page
                ->has('homeBannerDetails')));
});

it('requires sign-in and email verification to open a profile', function () {
    $user = User::factory()->unverified()->create();
    $url = route('profile.show', ['user' => $user->id]);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs($user)->get($url)->assertRedirect(route('verification.notice'));
});

it('does not expose another users profile including deferred requests', function (bool $publicProfile, bool $deferred) {
    $owner = User::factory()->create(['public_profile' => $publicProfile]);
    $viewer = User::factory()->create();
    $headers = $deferred ? [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
        'X-Inertia-Partial-Component' => 'Profile/Show',
        'X-Inertia-Partial-Data' => 'profile,homeBannerDetails,homeActivityOverview,homeAccountCompletion',
    ] : [];

    $this->actingAs($viewer)
        ->get(route('profile.show', ['user' => $owner->id]), $headers)
        ->assertNotFound();
})->with([true, false])->with([true, false]);

it('rejects edits through another users profile URL without changing either profile', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $owner->homeProfile()->create(['description' => 'Owner profile.']);
    $viewer->homeProfile()->create(['description' => 'Viewer profile.']);
    $this->mock(ManagedImageStorage::class)->shouldNotReceive('replaceUploadedImageIfPresent');

    $this->actingAs($viewer)
        ->put(route('profile.update', ['user' => $owner->id]), ['description' => 'Changed'])
        ->assertNotFound();

    expect($owner->fresh()->homeProfile->description)->toBe('Owner profile.')
        ->and($viewer->fresh()->homeProfile->description)->toBe('Viewer profile.');
});

it('saves the owners customization and returns to their profile', function () {
    $user = User::factory()->create();
    $url = route('profile.show', ['user' => $user->id]);

    $this->actingAs($user)
        ->from($url)
        ->put(route('profile.update', ['user' => $user->id]), ['description' => 'My new biography.'])
        ->assertRedirect($url)
        ->assertSessionHasNoErrors();

    expect($user->fresh()->homeProfile->description)->toBe('My new biography.');
});

it('localizes profile links without a locale prefix', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['locale' => 'fr'])
        ->get('/profile/'.$user->id)
        ->assertRedirect('/fr/profile/'.$user->id);
});
