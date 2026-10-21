<?php

namespace App\Http\Controllers;

use App\Exceptions\LodestoneFetchException;
use App\Exceptions\LodestoneInvalidInputException;
use App\Exceptions\LodestoneParseException;
use App\Http\Requests\AddOnboardingCharacterRequest;
use App\Models\Character;
use App\Services\Characters\CharacterClaimAccessService;
use App\Services\Characters\CharacterOnboardingService;
use App\Services\Characters\CharacterTransferCompletionService;
use App\Services\Characters\XIVAuthCharacterDirectory;
use App\Services\Lodestone\LodestoneCharacterSearchService;
use App\Support\Characters\CharacterRefreshStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class V2CharacterCreateController extends Controller
{
    public function __invoke(Request $request, LodestoneCharacterSearchService $search): Response
    {
        abort_unless(app()->environment('local'), 404);

        return Inertia::render('V2/Characters/Create', [
            'xivAuthLinked' => $request->user()->socialAccounts()->where('provider', 'xivauth')->exists(),
            'resumeXivAuth' => $request->query('import') === 'xivauth',
            'worldGroups' => $search->worldOptionsByDatacenter(),
        ]);
    }

    public function search(Request $request, LodestoneCharacterSearchService $search, CharacterOnboardingService $onboarding): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'world' => ['nullable', Rule::in($search->availableWorlds())],
        ]);

        return $this->withLodestoneErrors(fn () => response()->json([
            'characters' => $onboarding->searchCandidates($request->user(), trim($data['name']), $data['world'] ?? ''),
        ]));
    }

    public function xivAuthCharacters(Request $request, XIVAuthCharacterDirectory $directory): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);

        return response()->json(['characters' => $directory->forUser($request->user())]);
    }

    public function store(AddOnboardingCharacterRequest $request, CharacterOnboardingService $onboarding, CharacterClaimAccessService $access, CharacterTransferCompletionService $completion): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);

        return $this->withLodestoneErrors(function () use ($request, $onboarding, $access, $completion): JsonResponse {
            $result = $onboarding->add($request->user(), $request->validated('method'), $request->validated('lodestone_id'));
            if ($result->outcome !== 'added') {
                $access->grant($request->user(), $result->character);
            }

            return response()->json([
                'character' => $onboarding->summary($result->character),
                'outcome' => $result->outcome,
                'transferred' => $result->transferred,
                'transfer' => ($pending = $completion->pending($result->character, $request->user()))
                    ? $completion->summary($pending, $request->user()) : null,
            ])->header('Cache-Control', 'no-store, private');
        });
    }

    public function refresh(Request $request, Character $character, CharacterOnboardingService $onboarding): JsonResponse|StreamedResponse
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless((int) $character->user_id === (int) $request->user()->id, 404);

        if ($request->header('X-Character-Progress') === 'stream') {
            return $this->streamRefresh($character, $onboarding);
        }

        return $this->withLodestoneErrors(fn () => response()->json($onboarding->refresh($character)));
    }

    private function streamRefresh(Character $character, CharacterOnboardingService $onboarding): StreamedResponse
    {
        return response()->stream(function () use ($character, $onboarding): void {
            $send = static function (array $event): void {
                echo json_encode($event, JSON_THROW_ON_ERROR)."\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $result = $onboarding->refresh($character,
                    onProgress: fn (CharacterRefreshStage $stage, int $percent) => $send([
                        'type' => 'progress', 'stage' => $stage->value, 'percent' => $percent,
                    ]));
                $send(['type' => 'complete', ...$result]);
            } catch (LodestoneFetchException|LodestoneParseException|LodestoneInvalidInputException) {
                $send(['type' => 'error', 'code' => 'lodestone_unavailable']);
            } catch (ValidationException $exception) {
                $send(['type' => 'error', 'code' => ($exception->errors()['onboarding'][0] ?? null) === 'busy' ? 'busy' : 'generic']);
            } catch (\Throwable $exception) {
                report($exception);
                $send(['type' => 'error', 'code' => 'generic']);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function withLodestoneErrors(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (LodestoneFetchException|LodestoneParseException|LodestoneInvalidInputException) {
            throw ValidationException::withMessages(['onboarding' => 'lodestone_unavailable']);
        }
    }
}
