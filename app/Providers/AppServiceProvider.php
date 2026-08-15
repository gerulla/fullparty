<?php

namespace App\Providers;

use App\Http\Controllers\XivPluginDeviceAuthorizationController;
use App\Models\Activity;
use App\Models\Group;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Policies\GroupActivityPolicy;
use App\Support\Passport\XivPluginAuthorizationServerFactory;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Passport\Contracts\ApprovedDeviceAuthorizationResponse as ApprovedDeviceAuthorizationResponseContract;
use Laravel\Passport\Contracts\DeniedDeviceAuthorizationResponse as DeniedDeviceAuthorizationResponseContract;
use Laravel\Passport\Http\Responses\ApprovedDeviceAuthorizationResponse;
use Laravel\Passport\Http\Responses\DeniedDeviceAuthorizationResponse;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use SocialiteProviders\Discord\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->when(XivPluginDeviceAuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard(config('passport.guard', null)));

        $this->app->singleton(AuthorizationServer::class, fn ($app) => (new XivPluginAuthorizationServerFactory($app))
            ->make());

        $this->app->singleton(
            ApprovedDeviceAuthorizationResponseContract::class,
            fn () => new class extends ApprovedDeviceAuthorizationResponse
            {
                public function toResponse($request)
                {
                    return redirect()->route('dashboard')
                        ->with('status', 'authorization-approved');
                }
            }
        );

        $this->app->singleton(
            DeniedDeviceAuthorizationResponseContract::class,
            fn () => new class extends DeniedDeviceAuthorizationResponse
            {
                public function toResponse($request)
                {
                    return redirect()->route('xivplugin.device')
                        ->with('status', 'authorization-denied');
                }
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::defaults(['locale' => app()->getLocale()]);

        Passport::tokensCan([
            'xivplugin:read' => 'Read your FullParty account and character summary for the XIV plugin.',
        ]);
        Passport::tokensExpireIn(new DateInterval('PT1H'));
        Passport::refreshTokensExpireIn(new DateInterval('P30D'));
        Passport::deviceUserCodeView(fn (array $parameters) => Inertia::render('auth/XivPlugin/DeviceCode', [
            'prefilledUserCode' => (string) ($parameters['request']->old('user_code')
                ?: $parameters['request']->query('user_code', '')),
            'status' => $parameters['request']->session()->get('status'),
        ]));
        Passport::deviceAuthorizationView(fn (array $parameters) => Inertia::render('auth/XivPlugin/Authorize', [
            'client' => [
                'id' => $parameters['client']->id,
                'name' => $parameters['client']->name,
            ],
            'scopes' => collect($parameters['scopes'])
                ->map(fn ($scope) => [
                    'id' => $scope->id,
                    'description' => $scope->description,
                ])
                ->values()
                ->all(),
            'authToken' => $parameters['authToken'],
            'state' => $parameters['request']->query('state'),
            'userCode' => $parameters['request']->query('user_code'),
        ]));

        Gate::policy(Activity::class, GroupActivityPolicy::class);
        Gate::define('viewPulse', fn (?User $user) => (bool) $user?->is_admin);

        RateLimiter::for('login', function (Request $request) {
            $login = Str::lower(trim((string) $request->input('login', $request->input('email'))));

            return Limit::perMinute(5)->by(($login ?: 'unknown').'|'.$request->ip());
        });

        RateLimiter::for('auth.registration', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by(($email ?: 'unknown').'|'.$request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        RateLimiter::for('auth.email', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by(($email ?: 'unknown').'|'.$request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        RateLimiter::for('oauth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('guest.application', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('external.lookup', function (Request $request) {
            $actor = $request->user()
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return Limit::perMinute(30)->by($actor);
        });

        RateLimiter::for('invite', function (Request $request) {
            $actor = $request->user()
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return Limit::perMinute(30)->by($actor);
        });

        RateLimiter::for('group.create', fn (Request $request) => [
            Limit::perHour(2)->by('hour:user:'.$request->user()->id),
            Limit::perDay(5)->by('day:user:'.$request->user()->id),
        ]);

        RateLimiter::for('run.create', function (Request $request) {
            $group = $request->route('group');
            $groupId = $group instanceof Group ? $group->id : $group;

            return [
                Limit::perHour(30)->by('user:'.$request->user()->id),
                Limit::perHour(40)->by('group:'.$groupId),
            ];
        });

        RateLimiter::for('application.submit', function (Request $request) {
            $actor = $request->user()
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return [
                Limit::perMinute(5)->by('minute:'.$actor),
                Limit::perDay(50)->by('day:'.$actor),
            ];
        });

        RateLimiter::for('membership.write', fn (Request $request) => [
            Limit::perMinute(10)->by('minute:user:'.$request->user()->id),
            Limit::perHour(30)->by('hour:user:'.$request->user()->id),
        ]);

        RateLimiter::for('group.content.write', function (Request $request) {
            $group = $request->route('group');
            $groupId = $group instanceof Group ? $group->id : $group;

            return [
                Limit::perMinute(20)->by('user:'.$request->user()->id),
                Limit::perHour(100)->by('group:'.$groupId),
            ];
        });

        RateLimiter::for('integration.api', function (Request $request) {
            $client = $request->attributes->get('integration_client');
            $key = $client instanceof IntegrationClient
                ? 'client:'.$client->id
                : 'ip:'.$request->ip();

            return Limit::perMinute(180)->by($key);
        });

        RateLimiter::for('xivplugin.api', fn (Request $request) => Limit::perMinute(180)
            ->by('user:'.$request->user()->id));

        RateLimiter::for('admin.write', fn (Request $request) => Limit::perMinute(30)
            ->by('user:'.$request->user()->id));

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', Provider::class);
            $event->extendSocialite('xivauth', \SocialiteProviders\XIVAuth\Provider::class);
        });
    }
}
