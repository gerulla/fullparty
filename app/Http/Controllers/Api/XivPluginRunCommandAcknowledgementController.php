<?php

namespace App\Http\Controllers\Api;

use App\Events\XivPluginRunCommandAcknowledged;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Services\XivPlugin\XivPluginRunRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class XivPluginRunCommandAcknowledgementController extends Controller
{
    public function store(
        Request $request,
        Activity $activity,
        string $commandId,
        XivPluginRunRealtimeService $realtimeService,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'received',
                'ignored_not_targeted',
                'executed',
                'failed',
                'expired',
                'user_disabled_auto_execute',
            ])],
            'message' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        abort_unless($realtimeService->canAccessRun($activity, $user), 403);

        $acknowledgement = [
            'command_id' => $commandId,
            'run_id' => $activity->id,
            'group_id' => $activity->group_id,
            'status' => $validated['status'],
            'message' => $validated['message'] ?? null,
            'acknowledged_by' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $this->imageUrl($user->avatar_url),
            ],
            'slots' => $realtimeService->userSlotsPayload($activity, $user),
            'acknowledged_at' => Carbon::now()->toIso8601String(),
        ];

        broadcast(new XivPluginRunCommandAcknowledged($activity->id, $acknowledgement));

        return new JsonResponse([
            'data' => $acknowledgement,
        ]);
    }

    private function imageUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }
}
