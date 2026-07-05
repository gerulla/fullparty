<?php

namespace App\Http\Controllers\Api;

use App\Events\XivPluginRunPartySnapshotUpdated;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Services\XivPlugin\XivPluginRunRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class XivPluginRunPartySnapshotController extends Controller
{
    public function store(
        Request $request,
        Activity $activity,
        XivPluginRunRealtimeService $realtimeService,
    ): JsonResponse {
        $validated = $request->validate([
            'seq' => ['required', 'integer', 'min:0'],
            'party_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.:-]+$/'],
            'members' => ['required', 'array', 'min:1', 'max:8'],
            'members.*.p' => ['required', 'integer', 'min:1', 'max:8', 'distinct'],
            'members.*.cid' => ['sometimes', 'nullable', 'integer', 'exists:characters,id'],
            'members.*.n' => ['sometimes', 'nullable', 'string', 'max:80'],
            'members.*.w' => ['sometimes', 'nullable', 'string', 'max:80'],
            'members.*.cj' => ['required', 'integer', 'exists:character_classes,id'],
            'members.*.pj' => ['sometimes', 'nullable', 'integer', 'exists:phantom_jobs,id'],
        ]);

        $user = $request->user();

        abort_unless($realtimeService->canPublishPartySnapshot($activity, $user), 403);

        $partyKey = (string) $validated['party_key'];

        if (! $realtimeService->partyKeyExists($activity, $partyKey)) {
            throw ValidationException::withMessages([
                'party_key' => 'The selected party does not exist on this run.',
            ]);
        }

        $rateLimitKey = sprintf('xivplugin:party-snapshot:%d:%d', $activity->id, $user->id);

        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            return new JsonResponse([
                'message' => 'Too many party snapshots. Please wait before sending another update.',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 2);

        $snapshot = [
            'run_id' => $activity->id,
            'sender_user_id' => $user->id,
            'party_key' => $partyKey,
            'seq' => (int) $validated['seq'],
            'ts' => Carbon::now()->timestamp,
            'members' => $this->compactMembers($validated['members']),
        ];

        broadcast(new XivPluginRunPartySnapshotUpdated($activity->id, $snapshot));

        return new JsonResponse([
            'data' => $snapshot,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $members
     * @return array<int, array<string, mixed>>
     */
    private function compactMembers(array $members): array
    {
        return collect($members)
            ->map(function (array $member): array {
                if (! filled($member['cid'] ?? null) && (! filled($member['n'] ?? null) || ! filled($member['w'] ?? null))) {
                    throw ValidationException::withMessages([
                        'members' => 'Each party member must include either a character id or a character name and world.',
                    ]);
                }

                $payload = [
                    'p' => (int) $member['p'],
                    'cj' => (int) $member['cj'],
                ];

                if (filled($member['cid'] ?? null)) {
                    $payload['cid'] = (int) $member['cid'];
                } else {
                    $payload['n'] = (string) $member['n'];
                    $payload['w'] = (string) $member['w'];
                }

                if (filled($member['pj'] ?? null)) {
                    $payload['pj'] = (int) $member['pj'];
                }

                return $payload;
            })
            ->values()
            ->all();
    }
}
