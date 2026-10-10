<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects the naked home route to the localized home route', function () {
    $this->get('/')
        ->assertRedirect('/en');
});

it('redirects the naked login route to the localized login route', function () {
    $this->get('/auth/login')
        ->assertRedirect('/en/auth/login');
});

it('renders the localized login route with the requested locale when no preference exists', function () {
    $response = $this->get('/de/auth/login');

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('locale.current', 'de')
        )
        ->assertSee('<html lang="de" class="dark">', false);
});

it('remembers the first localized page in the session and a persistent cookie', function (string $locale) {
    $response = $this->get('/'.$locale.'/auth/login')
        ->assertOk()
        ->assertSessionHas('locale', $locale)
        ->assertCookie('locale', $locale);

    expect($response->getCookie('locale')->getExpiresTime())->toBeGreaterThan(now()->addYear()->timestamp);

    $otherLocale = $locale === 'en' ? 'de' : 'en';
    $this->get('/'.$otherLocale.'/auth/login?next=home')
        ->assertRedirect('/'.$locale.'/auth/login?next=home')
        ->assertSessionHas('locale', $locale)
        ->assertCookie('locale', $locale);
})->with(['en', 'de', 'fr', 'ja']);

it('remembers the locale on the first authenticated home visit', function () {
    $this->actingAs(User::factory()->create())
        ->get('/en/home')
        ->assertOk()
        ->assertSessionHas('locale', 'en')
        ->assertCookie('locale', 'en');

    $this->get('/de/home')->assertRedirect('/en/home');
});

it('restores the remembered locale from the cookie in a new session', function () {
    $this->get('/en/auth/login')->assertCookie('locale', 'en');
    $this->app['session']->flush();

    $this->withCookie('locale', 'en')->get('/de/auth/login')
        ->assertRedirect('/en/auth/login')
        ->assertSessionHas('locale', 'en')
        ->assertCookie('locale', 'en');
});

it('uses the first page locale when stored preferences are invalid', function () {
    $this->withSession(['locale' => 'invalid'])->withCookie('locale', 'invalid')
        ->get('/fr/auth/login')
        ->assertOk()
        ->assertSessionHas('locale', 'fr')
        ->assertCookie('locale', 'fr');
});

it('does not initialize a locale preference from a background json request', function () {
    $this->getJson('/de/changelog/latest')
        ->assertOk()
        ->assertSessionMissing('locale')
        ->assertCookieMissing('locale');

    $this->get('/ja/auth/login')
        ->assertOk()
        ->assertSessionHas('locale', 'ja')
        ->assertCookie('locale', 'ja');
});

it('allows the switcher to replace the automatically remembered locale', function () {
    $this->get('/en/auth/login')->assertSessionHas('locale', 'en');
    $this->postJson('/en/locale', ['locale' => 'de'])
        ->assertNoContent()
        ->assertSessionHas('locale', 'de')
        ->assertCookie('locale', 'de');
    $this->get('/en/auth/login')->assertRedirect('/de/auth/login');
});

it('redirects localized links to the remembered locale preference', function () {
    $this
        ->withSession(['locale' => 'fr'])
        ->get('/de/auth/login')
        ->assertRedirect('/fr/auth/login');
});

it('updates the remembered locale from the locale switcher endpoint', function () {
    $this
        ->from('/en/auth/login')
        ->post('/en/locale', ['locale' => 'ja'])
        ->assertRedirect('/en/auth/login')
        ->assertSessionHas('locale', 'ja')
        ->assertCookie('locale', 'ja');
});

it('can update the remembered locale as a json request', function () {
    $this
        ->postJson('/en/locale', ['locale' => 'de'])
        ->assertNoContent()
        ->assertSessionHas('locale', 'de')
        ->assertCookie('locale', 'de');
});

it('verifies an absolute signed email link without rewriting its locale', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
        'id' => $user->id, 'hash' => sha1($user->email), 'locale' => 'de',
    ]);

    $this->actingAs($user)->withSession(['locale' => 'en'])->get($url)
        ->assertRedirect(route('dashboard', ['locale' => 'en']))
        ->assertSessionHas('locale', 'en')
        ->assertCookie('locale', 'en');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('still rejects invalid signed email links when the preferred locale differs', function (string $invalid) {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', $invalid === 'expired' ? now()->subMinute() : now()->addMinutes(5), [
        'id' => $user->id, 'hash' => sha1($user->email), 'locale' => 'de',
    ]);
    if ($invalid === 'tampered') {
        $url .= '&extra=changed';
    }

    $this->actingAs($user)->withSession(['locale' => 'en'])->followingRedirects()->get($url)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->with(['expired', 'tampered']);

it('preserves relative signed URLs as well as absolute ones', function () {
    Route::middleware(['web', 'signed:relative'])->get('/{locale}/test-signed-link', fn () => response('Valid signed link'))
        ->whereIn('locale', ['en', 'de', 'fr', 'ja'])->name('test.signed-link');
    Route::getRoutes()->refreshNameLookups();
    $url = URL::temporarySignedRoute('test.signed-link', now()->addMinutes(5), ['locale' => 'de'], absolute: false);

    $this->withSession(['locale' => 'en'])->get($url)->assertOk()->assertSee('Valid signed link')
        ->assertSessionHas('locale', 'en');
});
