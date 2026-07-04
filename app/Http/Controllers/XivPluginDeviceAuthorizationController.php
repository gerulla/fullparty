<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\DeviceCodeRepository;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class XivPluginDeviceAuthorizationController extends Controller
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly DeviceCodeRepository $deviceCodes,
        private readonly ClientRepository $clients,
    ) {}

    public function __invoke(Request $request, DeviceAuthorizationViewResponse $viewResponse): RedirectResponse|DeviceAuthorizationViewResponse
    {
        if (! $userCode = $request->query('user_code')) {
            return redirect()->route('xivplugin.device');
        }

        $deviceCode = $this->deviceCodes->getDeviceCodeEntityByUserCode(
            str_replace('-', '', $userCode)
        );

        if (! $deviceCode) {
            return redirect()
                ->route('xivplugin.device')
                ->withInput(['user_code' => $userCode])
                ->withErrors([
                    'user_code' => __('xivplugin.device.invalid_code'),
                ]);
        }

        $user = $this->guard->user();
        $deviceCode->setUserIdentifier($user->getAuthIdentifier());

        $scopes = $this->parseScopes($deviceCode);
        $client = $this->clients->find($deviceCode->getClient()->getIdentifier());

        $request->session()->put('authToken', $authToken = Str::random());
        $request->session()->put('deviceCode', serialize($deviceCode));

        return $viewResponse->withParameters([
            'client' => $client,
            'user' => $user,
            'scopes' => $scopes,
            'request' => $request,
            'authToken' => $authToken,
        ]);
    }

    /**
     * @return Scope[]
     */
    private function parseScopes(DeviceCodeEntityInterface $deviceCode): array
    {
        return Passport::scopesFor(
            collect($deviceCode->getScopes())->map(
                fn (ScopeEntityInterface $scope): string => $scope->getIdentifier()
            )->unique()->all()
        );
    }
}
