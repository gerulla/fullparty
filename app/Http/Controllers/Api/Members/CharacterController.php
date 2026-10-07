<?php

namespace App\Http\Controllers\Api\Members;

use App\Http\Controllers\Controller;
use App\Http\Resources\CharacterDetailResource;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Support\Integrations\MemberPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CharacterController extends Controller
{
    private const RELATIONS = ['fieldValues.fieldDefinition', 'classes', 'phantomJobs', 'occultProgress'];

    public function index(Request $request): JsonResponse
    {
        $page = Character::where('user_id', $request->user()->id)->with(self::RELATIONS)
            ->orderByDesc('is_primary')->orderBy('id')->paginate(MemberPagination::size($request));
        $classes = CharacterClass::orderBy('role')->orderBy('name')->get();
        $jobs = PhantomJob::orderBy('name')->get();

        return response()->json(MemberPagination::payload($page, fn ($character) => (new CharacterDetailResource($character, $classes, $jobs))->resolve($request)));
    }

    public function show(Request $request, Character $character): JsonResponse
    {
        abort_unless((int) $character->user_id === $request->user()->id, 404);
        $character->loadMissing(self::RELATIONS);

        return response()->json(['data' => (new CharacterDetailResource($character, CharacterClass::orderBy('role')->orderBy('name')->get(), PhantomJob::orderBy('name')->get()))->resolve($request)]);
    }
}
