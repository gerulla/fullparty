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
