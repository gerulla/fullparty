<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('does not save a locale preference just from opening a localized link', function () {
    $this->get('/de/auth/login')
        ->assertOk()
        ->assertSessionMissing('locale')
        ->assertCookieMissing('locale');
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
