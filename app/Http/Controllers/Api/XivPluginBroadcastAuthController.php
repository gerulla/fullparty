<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Services\XivPlugin\XivPluginRunRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class XivPluginBroadcastAuthController extends Controller
{
    public function store(Request $request, XivPluginRunRealtimeService $realtimeService): JsonResponse
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:255'],
        ]);

        $channelName = (string) $validated['channel_name'];
        $activityId = $this->activityIdFromChannelName($channelName);

        if ($activityId === null) {
            throw ValidationException::withMessages([
                'channel_name' => 'Unsupported XIV plugin channel.',
            ]);
        }

        $activity = Activity::query()->findOrFail($activityId);
        $user = $request->user();

        abort_unless($realtimeService->canAccessRun($activity, $user), 403);

        $channelData = json_encode([
            'user_id' => (string) $user->id,
            'user_info' => $realtimeService->presenceUserInfo($activity, $user),
        ], JSON_THROW_ON_ERROR);

        return new JsonResponse([
            'auth' => $this->authSignature(
                (string) $validated['socket_id'],
                $channelName,
                $channelData,
            ),
            'channel_data' => $channelData,
        ]);
    }

    private function activityIdFromChannelName(string $channelName): ?int
    {
        if (! preg_match('/^presence-xivplugin\.runs\.(\d+)$/', $channelName, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function authSignature(string $socketId, string $channelName, string $channelData): string
    {
        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');

        abort_if(blank($key) || blank($secret), 503, 'Realtime is not configured.');

        $stringToSign = sprintf('%s:%s:%s', $socketId, $channelName, $channelData);

        return $key.':'.hash_hmac('sha256', $stringToSign, $secret);
    }
}
