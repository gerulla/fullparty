<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\Groups\Resources\ResourceReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicResourceController extends Controller
{
    // Public reader UI is a separate task. These endpoints expose its public-only data contract.
    public function index(Request $request, Group $group, ResourceReaderService $reader, ?string $collectionSlug = null): JsonResponse
    {
        return response()->json(['group' => $group->only(['name', 'slug', 'profile_picture_url'])] + $reader->index($group, $request, public: true, collectionSlug: $collectionSlug))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Group $group, string $slug, ResourceReaderService $reader): JsonResponse|RedirectResponse
    {
        $resource = $reader->resolve($group, $slug, null, public: true);
        if ($resource->slug !== $slug) {
            return redirect()->route('public-resources.show', ['group' => $group, 'slug' => $resource->slug], 302)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $reader->detail($resource, $request)])->header('Cache-Control', 'no-store');
    }
}
