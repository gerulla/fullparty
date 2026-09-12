<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\Groups\Resources\ResourceReaderHistoryService;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Support\Seo\ServerMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicResourceController extends Controller
{
    public function __construct(private readonly ServerMeta $meta) {}

    public function index(Request $request, Group $group, ResourceReaderService $reader, ?string $collectionSlug = null): JsonResponse|Response
    {
        $data = $this->pageContext($group) + $reader->index($group, $request, public: true, collectionSlug: $collectionSlug)
            + ($collectionSlug === null ? ['resource' => $reader->home($group, $request, public: true)] : []);

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json($data)->header('Cache-Control', 'no-store')
            : $this->render($data);
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
            : $this->render($this->pageContext($group) + ['resource' => $detail] + $reader->index($group, $request, public: true));
    }

    private function render(array $data): Response
    {
        $meta = $this->meta->resourceLibrary($data);

        return Inertia::render('Resources/Show', $data + ['seo' => $meta])->withViewData('serverMeta', $meta);
    }

    private function pageContext(Group $group): array
    {
        return [
            'group' => $group->only(['name', 'slug', 'description', 'datacenter', 'profile_picture_url', 'banner_image_url']),
            'locale' => ['current' => app()->getLocale()],
            'main_site_url' => config('app.url'),
        ];
    }

    public function history(Request $request, Group $group, string $slug, ResourceReaderService $reader, ResourceReaderHistoryService $history): JsonResponse
    {
        $resource = $reader->resolve($group, $slug, null, public: true);

        return response()->json(['data' => $history->remaining($resource, $request)])->header('Cache-Control', 'private, no-store');
    }
}
