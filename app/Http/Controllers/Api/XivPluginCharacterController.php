<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\XivPlugin\XivPluginCharacterListResource;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class XivPluginCharacterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $characters = Character::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'fieldValues.fieldDefinition',
                'classes',
                'phantomJobs',
                'occultProgress',
            ])
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        $characterClasses = CharacterClass::query()
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $phantomJobs = PhantomJob::query()
            ->orderBy('name')
            ->get();

        return (new XivPluginCharacterListResource(
            $characters,
            $characterClasses,
            $phantomJobs,
        ))->response();
    }
}
