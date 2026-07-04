<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class XivPluginRealtimeController extends Controller
{
    public function show(): JsonResponse
    {
        $key = config('broadcasting.connections.reverb.key');

        abort_if(blank($key), 503, 'Realtime is not configured.');

        return new JsonResponse([
            'data' => [
                'reverb' => [
                    'app_key' => $key,
                    'ws_host' => config('broadcasting.connections.reverb.options.host') ?: parse_url(config('app.url'), PHP_URL_HOST),
                    'ws_port' => (int) config('broadcasting.connections.reverb.options.port', 443),
                    'scheme' => config('broadcasting.connections.reverb.options.scheme', 'https') === 'https' ? 'wss' : 'ws',
                    'auth_endpoint' => route('api.xivplugin.broadcasting.auth'),
                ],
                'channels' => [
                    'run_presence' => 'presence-xivplugin.runs.{run_id}',
                ],
                'events' => [
                    'command' => 'xivplugin.run.command',
                    'command_acknowledged' => 'xivplugin.run.command.acknowledged',
                ],
            ],
        ]);
    }
}
