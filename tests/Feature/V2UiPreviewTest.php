<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('opens the v2 homepage preview for a signed-in local user', function () {
    app()->instance('env', 'local');

    $this->actingAs(User::factory()->create())
        ->get(route('ui-v2.home', ['locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('V2/Home')
            ->where('theme_preview_enabled', true));
});

it('requires sign-in for the local v2 homepage preview', function () {
    app()->instance('env', 'local');

    $this->get(route('ui-v2.home', ['locale' => 'en']))
        ->assertRedirect(route('login', ['locale' => 'en']));
});

it('does not expose the v2 homepage preview in production', function () {
    app()->instance('env', 'production');

    $this->actingAs(User::factory()->create())
        ->get(route('ui-v2.home', ['locale' => 'en']))
        ->assertNotFound();
});

it('only enables theme preview controls in development environments', function (string $environment, bool $enabled) {
    app()->instance('env', $environment);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard', ['locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('theme_preview_enabled', $enabled));
})->with([
    'development' => ['development', true],
    'production' => ['production', false],
    'staging' => ['staging', false],
]);
