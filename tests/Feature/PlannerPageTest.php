<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function main_site_route(string $name): string
{
    return rtrim((string) config('app.url'), '/').route($name, absolute: false);
}

it('renders the public planner shell with main site authentication routes', function () {
    $response = $this->get(route('planner.index'))
        ->assertOk()
        ->assertViewIs('planner')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('auth.user', null)
            ->where('planner.routes.login', main_site_route('login'))
            ->where('planner.routes.register', main_site_route('register')));

    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
    $sessionDomain = ltrim((string) config('session.domain'), '.');
    $combinedCookieHeaders = strtolower(
        implode('; ', $response->headers->all('set-cookie'))
    );
    preg_match_all('/(?:^|;\s*)domain=([^;]+)/', $combinedCookieHeaders, $domainMatches);
    $cookieDomains = collect($domainMatches[1] ?? [])
        ->map(fn (string $domain) => ltrim($domain, '.'))
        ->all();

    expect($sessionDomain)->toBe($appHost)
        ->and('plan.'.$appHost)->toEndWith($sessionDomain)
        ->and(config('session.cookie'))->toBe('fullparty-shared-session')
        ->and($cookieDomains)->toContain($sessionDomain);
});

it('shares the signed-in user and main site account routes with the planner', function () {
    $user = User::factory()->create([
        'name' => 'Planner User',
    ]);

    $this->actingAs($user)
        ->get(route('planner.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', 'Planner User')
            ->where('planner.routes.dashboard', main_site_route('dashboard'))
            ->where('planner.routes.settings', main_site_route('settings'))
            ->where('planner.routes.logout', main_site_route('logout')));
});
