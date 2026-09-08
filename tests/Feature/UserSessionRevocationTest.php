<?php

use App\Models\User;
use App\Services\Auth\UserSessionRevocationService;
use App\Services\Users\UserAccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\ClientRepository;

uses(RefreshDatabase::class);

function seedRevocationCredentials(User $user, string $id, bool $expired = false): void
{
    $client = app(ClientRepository::class)->createDeviceAuthorizationGrantClient('Revocation test', false);
    DB::table('oauth_access_tokens')->insert([
        'id' => $id, 'user_id' => $user->id, 'client_id' => $client->id,
        'scopes' => json_encode(['xivplugin:read']), 'revoked' => $expired,
        'created_at' => now(), 'updated_at' => now(),
        'expires_at' => $expired ? now()->subHour() : now()->addHour(),
    ]);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => $id.'-refresh', 'access_token_id' => $id,
        'revoked' => false, 'expires_at' => now()->addDays(30),
    ]);
    DB::table('sessions')->insert([
        'id' => $id.'-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp,
    ]);
}

it('revokes browser and plugin sessions on password reset or account deletion', function (string $action) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedRevocationCredentials($user, 'current');
    seedRevocationCredentials($user, 'expired', true);
    seedRevocationCredentials($other, 'other');
    $rememberToken = $user->remember_token;

    if ($action === 'reset') {
        $resetToken = Password::broker()->createToken($user);
        $this->post(route('password.update'), [
            'email' => $user->email, 'token' => $resetToken,
            'password' => 'NewSecurityPassword123!', 'password_confirmation' => 'NewSecurityPassword123!',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
        expect(Hash::check('NewSecurityPassword123!', $user->fresh()->password))->toBeTrue();
    } else {
        app(UserAccountDeletionService::class)->delete($user);
    }

    foreach (['current', 'expired'] as $id) {
        expect(app(AccessTokenRepository::class)->isAccessTokenRevoked($id))->toBeTrue()
            ->and(app(RefreshTokenRepository::class)->isRefreshTokenRevoked($id.'-refresh'))->toBeTrue();
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $id, 'revoked' => true]);
        $this->assertDatabaseMissing('sessions', ['id' => $id.'-session']);
    }
    expect($user->fresh()->remember_token)->not->toBe($rememberToken);
    $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'other', 'revoked' => false]);
    $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => 'other', 'revoked' => false]);
    $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
})->with(['reset', 'delete']);

it('can repeat revocation without affecting another user', function () {
    $user = User::factory()->create();
    seedRevocationCredentials($user, 'repeat');
    $service = app(UserSessionRevocationService::class);
    $service->revokeAll($user);
    $service->revokeAll($user);
    $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => 'repeat', 'revoked' => true]);
});
