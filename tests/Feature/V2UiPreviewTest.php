<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('opens v2 pages with preview controls for a signed-in local user', function (string $routeName, string $component) {
    app()->instance('env', 'local');

    $this->actingAs(User::factory()->create())
        ->get(route($routeName, ['locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('theme_preview_enabled', true));
})->with([
    'homepage' => ['dashboard', 'V2/Home'],
    'add character' => ['characters.create', 'V2/Characters/Create'],
]);

it('requires sign-in for local v2 previews', function (string $routeName) {
    app()->instance('env', 'local');

    $this->get(route($routeName, ['locale' => 'en']))
        ->assertRedirect(route('login', ['locale' => 'en']));
})->with(['dashboard', 'characters.create']);

it('does not expose unreleased character previews in production', function (string $routeName) {
    app()->instance('env', 'production');

    $this->actingAs(User::factory()->create())
        ->get(route($routeName, ['locale' => 'en']))
        ->assertNotFound();
})->with(['characters.create']);

it('only enables theme preview controls in development environments', function (string $environment, bool $enabled) {
    app()->instance('env', $environment);

    $user = User::factory()->create();
    $this->actingAs($user)
        ->get(route('profile.show', ['locale' => 'en', 'user' => $user->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('theme_preview_enabled', $enabled));
})->with([
    'development' => ['development', true],
    'production' => ['production', false],
    'staging' => ['staging', false],
]);
