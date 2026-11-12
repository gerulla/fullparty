<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('enables transitions by default and replaces the retired homepage preference', function () {
    $user = User::factory()->create()->refresh();
    expect($user->page_transition_enabled)->toBeTrue();

    $this->actingAs($user)->get(route('settings'))->assertOk()
        ->assertSee('data-page-transitions="on"', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.page_transition_enabled', true)
            ->missing('homepageGroups')->missing('auth.user.homepage_group_id'));
    $this->patchJson('/en/settings/homepage', ['homepage_group_id' => null])->assertNotFound();
    $this->putJson('/api/integrations/v1/me/homepage', ['homepage_group_id' => null])->assertNotFound();
});

it('persists the users transition opt-out and lets them enable it again', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $settings = route('settings', ['section' => 'general']);

    $this->actingAs($user)->from($settings)->patch(route('settings.page-transition'), [
        'page_transition_enabled' => false,
    ])->assertRedirect($settings);
    expect($user->fresh()->page_transition_enabled)->toBeFalse()
        ->and($otherUser->fresh()->page_transition_enabled)->toBeTrue();
    $this->get($settings)->assertOk()->assertSee('data-page-transitions="off"', false)
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.page_transition_enabled', false));

    $this->from($settings)->patch(route('settings.page-transition'), [
        'page_transition_enabled' => true,
    ])->assertRedirect($settings);
    expect($user->fresh()->page_transition_enabled)->toBeTrue();
});

it('requires an authenticated user and a valid transition preference', function () {
    $url = route('settings.page-transition');
    $this->patchJson($url, ['page_transition_enabled' => false])->assertUnauthorized();
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson($url, ['page_transition_enabled' => 'invalid'])
        ->assertUnprocessable()->assertJsonValidationErrors('page_transition_enabled');
    $this->patchJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('page_transition_enabled');
    expect($user->fresh()->page_transition_enabled)->toBeTrue();
});
