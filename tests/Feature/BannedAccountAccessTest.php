<?php

use App\Models\ContentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects banned page visits to a localized notice without protected dashboard data', function (string $locale) {
    $user = User::factory()->create(['banned_at' => now()]);
    config(['services.project_links.discord' => 'https://discord.gg/fullparty-test']);

    $this->actingAs($user)->get(route('dashboard', ['locale' => $locale]))
        ->assertRedirect(route('account.banned', ['locale' => $locale]));

    $this->get(route('account.banned', ['locale' => $locale]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Banned')
            ->where('discordUrl', 'https://discord.gg/fullparty-test')
            ->where('locale.current', $locale)->where('auth.user.id', $user->id)
            ->missing('navigation')->missing('notifications')->missing('onboarding'));
})->with(['en', 'de', 'fr', 'ja']);

it('redirects Inertia navigation and converts rejected mutations to a safe GET', function () {
    $user = User::factory()->create(['banned_at' => now()]);
    $this->actingAs($user)->withHeaders(['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])
        ->get(route('dashboard'))->assertRedirect(route('account.banned'));

    $this->post(route('reports.store'), ['target_type' => 'resource', 'target_id' => 1, 'reason' => 'spam'])
        ->assertStatus(303)->assertRedirect(route('account.banned'));
    expect(ContentReport::count())->toBe(0);
});

it('keeps JSON requests forbidden while allowing feedback, language changes and logout', function () {
    $user = User::factory()->create(['banned_at' => now()]);
    $this->actingAs($user)->getJson(route('account.notifications.summary'))->assertForbidden();
    $this->getJson(route('reports.feedback.pending'))->assertOk();
    $this->postJson(route('locale.update'), ['locale' => 'de'])->assertNoContent();
    $this->get(route('account.banned', ['locale' => 'de']))->assertOk();
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('shows the notice before email verification and requires authentication', function () {
    $this->get(route('account.banned'))->assertRedirect(route('login'));
    $user = User::factory()->unverified()->create(['banned_at' => now()]);
    $this->actingAs($user)->get(route('account.banned'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Banned'));
});

it('returns an unbanned user to the dashboard and allows protected access again', function () {
    $user = User::factory()->create(['banned_at' => now()]);
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('account.banned'));
    $user->forceFill(['banned_at' => null])->save();
    $this->actingAs($user->fresh())->get(route('account.banned'))->assertRedirect(route('dashboard'));
    $this->getJson(route('account.notifications.summary'))->assertOk();
});
