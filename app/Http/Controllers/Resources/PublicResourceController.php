<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\Groups\Resources\ResourceReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicResourceController extends Controller
{
    public function index(Request $request, Group $group, ResourceReaderService $reader, ?string $collectionSlug = null): JsonResponse|Response
    {
        $data = ['group' => $group->only(['name', 'slug', 'profile_picture_url'])] + $reader->index($group, $request, public: true, collectionSlug: $collectionSlug)
            + ($collectionSlug === null ? ['resource' => $reader->home($group, $request, public: true)] : []);

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json($data)->header('Cache-Control', 'no-store')
            : Inertia::render('Resources/Show', $data);
    }

    public function show(Request $request, Group $group, string $slug, ResourceReaderService $reader): JsonResponse|RedirectResponse|Response
    {
        $resource = $reader->resolve($group, $slug, null, public: true);
        if ($resource->uuid !== $slug) {
            return redirect()->route('public-resources.show', ['group' => $group, 'slug' => $resource->uuid], 302)->header('Cache-Control', 'no-store');
        }

        $detail = $reader->detail($resource, $request);

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['data' => $detail])->header('Cache-Control', 'no-store')
            : Inertia::render('Resources/Show', ['group' => $group->only(['name', 'slug', 'profile_picture_url']), 'resource' => $detail]);
    }
}
