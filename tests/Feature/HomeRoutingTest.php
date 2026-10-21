<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

it('serves the participant homepage in production without development controls', function () {
    app()->instance('env', 'production');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/en/home')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('V2/Home')
            ->where('theme_preview_enabled', false)
            ->loadDeferredProps('v2-user', fn (Assert $page) => $page
                ->where('user.id', $user->id)));
});

it('takes signed-in users from the site root to home in their preferred locale', function (string $locale) {
    $this->actingAs(User::factory()->create())
        ->withSession(['locale' => $locale])
        ->followingRedirects()->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('V2/Home')
            ->url('/'.$locale.'/home')
            ->where('locale.current', $locale));
})->with(['en', 'de', 'fr', 'ja']);

it('keeps the public landing page for guests at the site root', function () {
    $this->followingRedirects()->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')->url('/en'));
});

it('still requires authentication and email verification for the homepage', function () {
    $this->get('/en/home')->assertRedirect(route('login', ['locale' => 'en']));

    $this->actingAs(User::factory()->unverified()->create())
        ->get('/en/home')->assertRedirect(route('verification.notice', ['locale' => 'en']));
});

it('does not allow banned accounts into the homepage', function () {
    $this->actingAs(User::factory()->create(['banned_at' => now()]))
        ->get('/en/home')->assertRedirect(route('account.banned', ['locale' => 'en']));
});

it('removes the separate preview homepage URL and route name', function () {
    $this->actingAs(User::factory()->create())
        ->get('/en/v2/home')->assertNotFound();

    expect(Route::has('ui-v2.home'))->toBeFalse();
});
