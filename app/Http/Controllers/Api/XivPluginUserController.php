<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\XivPlugin\XivPluginUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class XivPluginUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('primaryCharacter');

        return (new XivPluginUserResource($user))->response();
    }
}
