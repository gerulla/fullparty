<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires a verified website administrator to open the admin panel', function () {
    $this->get(route('admin.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();
    $this->actingAs(User::factory()->unverified()->create(['is_admin' => true]))
        ->get(route('admin.index'))->assertRedirect(route('verification.notice'));
});

it('opens the admin panel in each supported language', function (string $locale) {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('admin.index', ['locale' => $locale]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Index')
            ->where('auth.user.is_admin', true)->where('locale.current', $locale));
})->with(['en', 'de', 'fr', 'ja']);

it('keeps the existing admin destinations accessible directly', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    foreach (['admin.character-data' => 'Admin/CharacterData', 'admin.system-data' => 'Admin/SystemData', 'admin.reports.index' => 'Admin/Reports'] as $route => $component) {
        $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
    }
});
