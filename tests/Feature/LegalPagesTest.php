<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares configured legal controller details with the public legal pages', function () {
    config()->set('services.legal.controller_name', 'Test Operator');
    config()->set('services.legal.contact_email', 'legal@test.example');

    $this->get(route('legal.privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Legal/PrivacyPolicy')
            ->where('legal.controller_name', 'Test Operator')
            ->where('legal.contact_email', 'legal@test.example')
        );
});

it('shows the privacy policy page publicly', function () {
    $this->get(route('legal.privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Legal/PrivacyPolicy')
        );
});

it('shows the cookies policy page publicly', function () {
    $this->get(route('legal.cookies'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Legal/CookiesPolicy')
        );
});

it('describes the deployed session cookie settings in the cookies policy', function (bool $expiresOnClose) {
    config()->set('session.cookie', 'custom-session-name');
    config()->set('session.lifetime', 90);
    config()->set('session.expire_on_close', $expiresOnClose);

    $this->get(route('legal.cookies'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Legal/CookiesPolicy')
            ->where('cookie_settings.session_name', 'custom-session-name')
            ->where('cookie_settings.session_minutes', 90)
            ->where('cookie_settings.session_expires_on_close', $expiresOnClose)
        );
})->with([true, false]);
