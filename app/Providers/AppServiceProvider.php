<?php

namespace App\Providers;

use App\Models\Activity;
use App\Models\User;
use App\Policies\GroupActivityPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Activity::class, GroupActivityPolicy::class);
        Gate::define('viewPulse', fn (?User $user) => (bool) $user?->is_admin);

		Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
			$event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
			$event->extendSocialite('xivauth', \SocialiteProviders\XIVAuth\Provider::class);
		});
    }
}
