<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\Groups\Resources\ResourceHolsterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceHolsterController extends Controller
{
    public function update(Request $request, Group $group, ResourceHolsterService $holsters): JsonResponse
    {
        return response()->json(['data' => $holsters->configure($group, $request->user(), $request->all())]);
    }
}
