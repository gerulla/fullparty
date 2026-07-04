<?php

namespace App\Http\Controllers\Api;

use App\Events\XivPluginRunCommandIssued;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Services\XivPlugin\XivPluginRunRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class XivPluginRunCommandController extends Controller
{
    public function store(
        Request $request,
        Activity $activity,
        XivPluginRunRealtimeService $realtimeService,
    ): JsonResponse {
        $allowedCommands = config('xivplugin.commands.allowed', []);

        $validated = $request->validate([
            'command' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9_.:-]+$/',
                Rule::in($allowedCommands),
            ],
            'target' => ['required', 'array'],
            'target.type' => ['required', 'string', Rule::in(['all_assigned', 'party_leads', 'hosts', 'users', 'slots'])],
            'target.user_ids' => ['sometimes', 'array'],
            'target.user_ids.*' => ['integer'],
            'target.slot_ids' => ['sometimes', 'array'],
            'target.slot_ids.*' => ['integer'],
            'payload' => ['sometimes', 'array'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'expires_in_seconds' => ['sometimes', 'integer', 'min:5', 'max:300'],
        ]);

        $user = $request->user();

        abort_unless($realtimeService->canIssueCommand($activity, $user), 403);

        $target = $realtimeService->resolveTarget($activity, $validated['target']);

        if ($target['user_ids'] === []) {
            throw ValidationException::withMessages([
                'target' => 'The selected command target does not include any assigned users.',
            ]);
        }

        $issuedAt = Carbon::now();
        $expiresAt = $issuedAt->copy()->addSeconds((int) ($validated['expires_in_seconds'] ?? 30));

        $command = [
            'command_id' => (string) Str::ulid(),
            'run_id' => $activity->id,
            'group_id' => $activity->group_id,
            'command' => (string) $validated['command'],
            'target' => $target,
            'issued_by' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'avatar_url' => $this->imageUrl($user->avatar_url),
            ],
            'payload' => Arr::get($validated, 'payload', []),
            'idempotency_key' => $validated['idempotency_key'] ?? null,
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        broadcast(new XivPluginRunCommandIssued($activity->id, $command));

        return new JsonResponse([
            'data' => $command,
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
