<?php

use App\Http\Controllers\Api\IntegrationGuildController;
use App\Http\Controllers\Api\IntegrationRunController;
use App\Http\Controllers\Api\IntegrationUserController;
use App\Models\IntegrationClient;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations')
    ->group(function () {
        Route::middleware(['integration.client:'.IntegrationClient::SCOPE_USERS_READ, 'throttle:integration.api'])
            ->post('/discord-users/primary-characters', [IntegrationUserController::class, 'primaryCharacters'])
            ->name('api.integrations.discord-users.primary-characters.index');

        Route::middleware(['integration.client:'.IntegrationClient::SCOPE_USERS_WRITE, 'throttle:integration.api'])
            ->post('/discord-users/link', [IntegrationUserController::class, 'link'])
            ->name('api.integrations.discord-users.link');

        Route::middleware(['integration.client:'.IntegrationClient::SCOPE_GUILDS_WRITE, 'throttle:integration.api'])
            ->post('/discord-guilds/link', [IntegrationGuildController::class, 'link'])
            ->name('api.integrations.discord-guilds.link');

        Route::middleware(['integration.client:'.IntegrationClient::SCOPE_RUNS_READ, 'throttle:integration.api'])
            ->group(function () {
                Route::get('/discord-guilds/{discordGuildId}/upcoming-runs', [IntegrationGuildController::class, 'upcomingRuns'])
                    ->whereNumber('discordGuildId')
                    ->name('api.integrations.discord-guilds.upcoming-runs.index');

                Route::get('/discord-guilds/{discordGuildId}/runs/{activity}/role-assignment', [IntegrationGuildController::class, 'roleAssignment'])
                    ->whereNumber('discordGuildId')
                    ->name('api.integrations.discord-guilds.runs.role-assignment');

                Route::get('/discord-users/{discordUserId}/upcoming-runs', [IntegrationUserController::class, 'upcomingRuns'])
                    ->whereNumber('discordUserId')
                    ->name('api.integrations.discord-users.upcoming-runs.index');

                Route::get('/discord-users/{discordUserId}/applications', [IntegrationUserController::class, 'applications'])
                    ->whereNumber('discordUserId')
                    ->name('api.integrations.discord-users.applications.index');

                Route::get('/runs/{activity}', [IntegrationRunController::class, 'show'])
                    ->name('api.integrations.runs.show');
            });
    });
