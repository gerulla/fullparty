<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\XivPlugin\XivPluginGroupListResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class XivPluginGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $groups = Group::query()
            ->with(['memberships' => fn ($query) => $query->where('user_id', $user->id)])
            ->where('owner_id', $user->id)
            ->orWhereHas('memberships', fn ($query) => $query->where('user_id', $user->id))
            ->orderBy('name')
            ->get();

        return (new XivPluginGroupListResource($groups, $user))->response();
    }
}
