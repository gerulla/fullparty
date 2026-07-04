<?php

namespace App\Support\Passport;

use DateInterval;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Bridge;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\DeviceCodeGrant;

class XivPluginAuthorizationServerFactory extends PassportServiceProvider
{
    public function make(): AuthorizationServer
    {
        return tap($this->makeAuthorizationServer(), function (AuthorizationServer $server): void {
            $server->enableGrantType(
                $this->makeAuthCodeGrant(), Passport::tokensExpireIn()
            );

            $server->enableGrantType(
                $this->makeRefreshTokenGrant(), Passport::tokensExpireIn()
            );

            if (Passport::$passwordGrantEnabled) {
                $server->enableGrantType(
                    $this->makePasswordGrant(), Passport::tokensExpireIn()
                );
            }

            $server->enableGrantType(
                new ClientCredentialsGrant, Passport::clientCredentialsTokensExpireIn() ?? Passport::tokensExpireIn()
            );

            if (Passport::$implicitGrantEnabled) {
                $server->enableGrantType(
                    $this->makeImplicitGrant(), Passport::tokensExpireIn()
                );
            }

            if (Passport::$deviceCodeGrantEnabled && Route::has('xivplugin.device')) {
                $server->enableGrantType(
                    $this->makeDeviceCodeGrant(), Passport::tokensExpireIn()
                );
            }
        });
    }

    protected function makeDeviceCodeGrant(): DeviceCodeGrant
    {
        return tap(new DeviceCodeGrant(
            $this->app->make(Bridge\DeviceCodeRepository::class),
            $this->app->make(Bridge\RefreshTokenRepository::class),
            new DateInterval('PT10M'),
            route('xivplugin.device'),
            5
        ), function (DeviceCodeGrant $grant) {
            $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
            $grant->setIncludeVerificationUriComplete(true);
            $grant->setIntervalVisibility(true);
        });
    }
}
