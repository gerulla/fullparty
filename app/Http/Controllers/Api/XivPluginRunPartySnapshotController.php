<?php

namespace App\Http\Controllers\Api;

use App\Events\XivPluginRunPartySnapshotUpdated;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\PhantomJob;
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
        $this->normalizeSnapshotJobNames($request);

        $validated = $request->validate([
            'seq' => ['required', 'integer', 'min:0'],
            'party_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.:-]+$/'],
            'members' => ['required', 'array', 'min:1', 'max:8'],
            'members.*.p' => ['required', 'integer', 'min:1', 'max:8', 'distinct'],
            'members.*.cid' => ['sometimes', 'nullable', 'integer', 'exists:characters,id'],
            'members.*.n' => ['sometimes', 'nullable', 'string', 'max:80'],
            'members.*.w' => ['sometimes', 'nullable', 'string', 'max:80'],
            'members.*.cj' => ['required', 'string', 'max:8', 'exists:character_classes,shorthand'],
            'members.*.pj' => ['sometimes', 'nullable', 'string', 'max:80', 'exists:phantom_jobs,name'],
            'members.*.r' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
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
                    'cj' => (string) $member['cj'],
                ];

                if (filled($member['cid'] ?? null)) {
                    $payload['cid'] = (int) $member['cid'];
                } else {
                    $payload['n'] = (string) $member['n'];
                    $payload['w'] = (string) $member['w'];
                }

                if (filled($member['pj'] ?? null)) {
                    $payload['pj'] = (string) $member['pj'];
                }

                if (filled($member['r'] ?? null)) {
                    $payload['r'] = (int) $member['r'];
                }

                return $payload;
            })
            ->values()
            ->all();
    }

    private function normalizeSnapshotJobNames(Request $request): void
    {
        $members = $request->input('members');

        if (! is_array($members)) {
            return;
        }

        $phantomJobNames = PhantomJob::query()
            ->pluck('name')
            ->mapWithKeys(fn (string $name): array => [strtolower(trim($name)) => $name])
            ->all();

        $request->merge([
            'members' => collect($members)
                ->map(function (mixed $member) use ($phantomJobNames): mixed {
                    if (! is_array($member)) {
                        return $member;
                    }

                    if (array_key_exists('cj', $member)) {
                        $member['cj'] = strtoupper(trim((string) $member['cj']));
                    }

                    if (array_key_exists('pj', $member)) {
                        $phantomJobName = trim((string) $member['pj']);

                        $member['pj'] = $phantomJobName === ''
                            ? null
                            : ($phantomJobNames[strtolower($phantomJobName)] ?? $phantomJobName);
                    }

                    return $member;
                })
                ->all(),
        ]);
    }
}
