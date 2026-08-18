<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires a website admin to access the calculator shell', function () {
    $this->get(route('calculator.index'))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('calculator.index'))
        ->assertForbidden();
});

it('requires a website admin to access the calculator catalog', function () {
    $this->get(route('calculator.catalog'))
        ->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('calculator.catalog'))
        ->assertForbidden();
});

it('renders the calculator shell for website admins', function () {
    $mainSiteRoute = fn (string $name): string => rtrim((string) config('app.url'), '/').route($name, absolute: false);
    $user = User::factory()->admin()->create([
        'name' => 'Calculator Admin',
    ]);

    $response = $this->actingAs($user)
        ->get(route('calculator.index'))
        ->assertOk()
        ->assertViewIs('calculator')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', 'Calculator Admin')
            ->where('calculator.routes.dashboard', $mainSiteRoute('dashboard'))
            ->where('calculator.routes.settings', $mainSiteRoute('settings'))
            ->where('calculator.routes.logout', $mainSiteRoute('logout'))
            ->has('calculator.csrf_token'));

    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
    $sessionDomain = (string) config('session.domain');
    $combinedCookieHeaders = strtolower(
        implode('; ', $response->headers->all('set-cookie'))
    );
    preg_match_all('/(?:^|;\s*)domain=([^;]+)/', $combinedCookieHeaders, $domainMatches);
    $cookieDomains = collect($domainMatches[1] ?? [])
        ->map(fn (string $domain) => ltrim($domain, '.'))
        ->all();

    expect($sessionDomain)->toBe($appHost)
        ->and('math.'.$appHost)->toEndWith($sessionDomain)
        ->and(config('session.cookie'))->toBe('fullparty-shared-session')
        ->and($cookieDomains)->toContain($sessionDomain);
});
