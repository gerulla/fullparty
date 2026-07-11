<?php

use App\Http\Controllers\Api\XivPluginBroadcastAuthController;
use App\Http\Controllers\Api\XivPluginCharacterController;
use App\Http\Controllers\Api\XivPluginGroupController;
use App\Http\Controllers\Api\XivPluginGroupRunController;
use App\Http\Controllers\Api\XivPluginRealtimeController;
use App\Http\Controllers\Api\XivPluginRunCheckInController;
use App\Http\Controllers\Api\XivPluginRunCommandAcknowledgementController;
use App\Http\Controllers\Api\XivPluginRunCommandController;
use App\Http\Controllers\Api\XivPluginRunController;
use App\Http\Controllers\Api\XivPluginRunPartySnapshotController;
use App\Http\Controllers\Api\XivPluginRunSlotApplicationController;
use App\Http\Controllers\Api\XivPluginUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('xivplugin')
    ->as('api.xivplugin.')
    ->middleware(['auth:api', 'scope:xivplugin:read'])
    ->group(function () {
        Route::get('/me', [XivPluginUserController::class, 'show'])
            ->name('me');

        Route::get('/characters', [XivPluginCharacterController::class, 'index'])
            ->name('characters.index');

        Route::get('/realtime', [XivPluginRealtimeController::class, 'show'])
            ->name('realtime.show');

        Route::post('/broadcasting/auth', [XivPluginBroadcastAuthController::class, 'store'])
            ->name('broadcasting.auth');

        Route::get('/groups', [XivPluginGroupController::class, 'index'])
            ->name('groups.index');

        Route::get('/groups/{group:slug}/runs', [XivPluginGroupRunController::class, 'index'])
            ->name('groups.runs.index');

        Route::get('/runs/{activity}', [XivPluginRunController::class, 'show'])
            ->name('runs.show');

        Route::post('/runs/{activity}/commands', [XivPluginRunCommandController::class, 'store'])
            ->name('runs.commands.store');

        Route::post('/runs/{activity}/party-snapshot', [XivPluginRunPartySnapshotController::class, 'store'])
            ->name('runs.party-snapshot.store');

        Route::post('/runs/{activity}/check-ins', [XivPluginRunCheckInController::class, 'store'])
            ->name('runs.check-ins.store');

        Route::post('/runs/{activity}/commands/{commandId}/ack', [XivPluginRunCommandAcknowledgementController::class, 'store'])
            ->where('commandId', '[A-Za-z0-9_-]+')
            ->name('runs.commands.acknowledgements.store');

        Route::get('/runs/{activity}/slots/{slot}/application', [XivPluginRunSlotApplicationController::class, 'show'])
            ->name('runs.slots.application.show');
    });
