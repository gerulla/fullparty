<?php

namespace App\Http\Controllers;

use App\Exceptions\LodestoneFetchException;
use App\Exceptions\LodestoneInvalidInputException;
use App\Exceptions\LodestoneParseException;
use App\Models\Character;
use App\Services\Characters\CharacterClaimAccessService;
use App\Services\Characters\CharacterOnboardingService;
use App\Services\Characters\CharacterTransferCompletionService;
use App\Services\Characters\LodestoneCharacterClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class V2CharacterClaimController extends Controller
{
    public function __construct(
        private readonly LodestoneCharacterClaimService $claims,
        private readonly CharacterOnboardingService $characters,
        private readonly CharacterClaimAccessService $access,
        private readonly CharacterTransferCompletionService $completion,
    ) {}

    public function show(Request $request, Character $character): Response
    {
        $this->authorizeAccess($request, $character);

        return Inertia::render('V2/Characters/Claim', [
            'character' => $this->characters->summary($character),
            'context' => $this->context($request, $character),
            'transfer' => $this->pendingTransfer($request, $character),
        ]);
    }

    public function code(Request $request, Character $character): JsonResponse
    {
        $this->authorizeAccess($request, $character);
        if ((int) $character->user_id !== (int) $request->user()->id) {
            try {
                $challenge = $this->claims->issueTransferCode($request->user(), $character);
            } catch (ValidationException $exception) {
                return $this->handleConflict($exception, $character);
            }

            return response()->json([
                'character' => $this->characters->summary($character->fresh()),
                'context' => 'transfer', ...$challenge,
            ])->header('Cache-Control', 'no-store, private');
        }
        $character = $this->claims->issueCode($request->user(), $character);

        return response()->json([
            'character' => $this->characters->summary($character),
            'code' => $character->isClaimed() ? null : $character->token,
            'expires_at' => $character->isClaimed() ? null : $character->expires_at?->toIso8601String(),
            'context' => 'owned',
            'transfer' => $this->pendingTransfer($request, $character),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function verify(Request $request, Character $character): JsonResponse
    {
        $this->authorizeAccess($request, $character);
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);

        try {
            $transferred = (int) $character->user_id !== (int) $request->user()->id;
            $character = $transferred
                ? $this->claims->verifyTransfer($request->user(), $character, $validated['code'])
                : $this->claims->verify($request->user(), $character, $validated['code']);
        } catch (LodestoneFetchException|LodestoneParseException|LodestoneInvalidInputException) {
            throw ValidationException::withMessages(['claim' => 'lodestone_unavailable']);
        } catch (ValidationException $exception) {
            return $this->handleConflict($exception, $character);
        }

        return response()->json([
            'character' => $this->characters->summary($character), 'transferred' => $transferred,
            'transfer' => $this->pendingTransfer($request, $character),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function handleConflict(ValidationException $exception, Character $character): JsonResponse
    {
        if (($exception->errors()['claim'][0] ?? null) !== 'already_claimed') {
            throw $exception;
        }

        return response()->json([
            'outcome' => 'already_claimed', 'character' => $this->characters->summary($character->fresh()),
        ], 409)->header('Cache-Control', 'no-store, private');
    }

    private function context(Request $request, Character $character): string
    {
        return (int) $character->user_id === (int) $request->user()->id
            ? 'owned' : ($character->isClaimed() ? 'already_claimed' : 'transfer');
    }

    private function pendingTransfer(Request $request, Character $character): ?array
    {
        $pending = $this->completion->pending($character, $request->user());

        return $pending ? $this->completion->summary($pending, $request->user()) : null;
    }

    private function authorizeAccess(Request $request, Character $character): void
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless($character->user_id !== null && $this->access->allows($request->user(), $character), 404);
    }
}
