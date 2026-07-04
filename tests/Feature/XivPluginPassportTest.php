<?php

use App\Http\Controllers\Api\XivPluginUserController;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Contracts\ApprovedDeviceAuthorizationResponse;

uses(RefreshDatabase::class);

it('uses xivplugin as the Passport device verification page', function () {
    expect(route('xivplugin.device', [], false))->toBe('/xivplugin');

    $this->get(route('xivplugin.device'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/XivPlugin/DeviceCode')
            ->where('prefilledUserCode', '')
        );
});

it('sends plugin user codes into Passport device authorization', function () {
    $this->get(route('xivplugin.device', ['user_code' => 'ABCD-EFGH']))
        ->assertRedirect(route('xivplugin.device.authorize', [
            'user_code' => 'ABCD-EFGH',
        ]));
});

it('keeps invalid plugin user codes on the xivplugin page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('xivplugin.device.authorize', ['user_code' => 'BAD-CODE']))
        ->assertRedirect(route('xivplugin.device'))
        ->assertSessionHasErrors(['user_code']);
});

it('sends approved plugin connections to the user dashboard', function () {
    $request = Request::create(route('xivplugin.device.authorize'), 'POST');
    $request->setLaravelSession(app('session.store'));

    $response = app(ApprovedDeviceAuthorizationResponse::class)->toResponse($request);

    expect($response->getTargetUrl())->toBe(route('dashboard'))
        ->and($request->session()->get('status'))->toBe('authorization-approved');
});

it('returns the authenticated plugin user summary', function () {
    $user = User::factory()->create();
    $character = Character::factory()->for($user)->create([
        'is_primary' => true,
        'name' => 'Giki Chomusuke',
        'world' => 'Lich',
        'datacenter' => 'Light',
    ]);

    $request = Request::create(route('api.xivplugin.me'), 'GET');
    $request->setUserResolver(fn () => $user);

    $payload = app(XivPluginUserController::class)->show($request)->getData(true);

    expect($payload['user']['id'])->toBe($user->id)
        ->and($payload['user']['primary_character']['id'])->toBe($character->id)
        ->and($payload['user']['primary_character']['name'])->toBe('Giki Chomusuke')
        ->and($payload['user']['primary_character']['world'])->toBe('Lich')
        ->and($payload['user']['primary_character']['datacenter'])->toBe('Light');
});
