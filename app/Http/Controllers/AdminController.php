<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditLogIndexRequest;
use App\Models\AuditLog;
use App\Models\BozjaItem;
use App\Models\CharacterClass;
use App\Models\CharacterFieldDefinition;
use App\Models\PhantomJob;
use App\Models\RaidPosition;
use App\Services\Audit\AuditLogFeedService;
use App\Services\Audit\AuditLogPresenter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/Index');
    }

    /**
     * Display the consolidated character data admin page.
     */
    public function characterData(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/CharacterData', [
            'definitions' => CharacterFieldDefinition::ordered()->get(),
            'characterClasses' => CharacterClass::query()
                ->orderBy('role')
                ->orderBy('name')
                ->get(),
            'phantomJobs' => PhantomJob::query()
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function auditLog(AuditLogIndexRequest $request, AuditLogFeedService $feed, AuditLogPresenter $presenter): Response|JsonResponse
    {
        $this->authorizeAdminAccess();

        $query = AuditLog::query();
        $page = $feed->paginate($query, $request->filters(), $request->validated('cursor'), siteWide: true);
        $payload = [
            'nextCursor' => $page->nextCursor()?->encode(),
            'auditLogs' => $presenter->rows($page->getCollection()),
        ];

        if ($request->wantsJson()) {
            if ($request->boolean('include_options')) {
                $payload['filters'] = $feed->options($query);
            }

            return response()->json($payload);
        }

        return Inertia::render('Admin/AuditLog', [
            ...$payload,
            'selectedFilters' => $request->filters(),
            'filters' => $feed->options($query),
        ]);
    }

    public function auditEvent(AuditLog $auditLog, AuditLogPresenter $presenter): JsonResponse
    {
        $this->authorizeAdminAccess();

        return response()->json(['event' => $presenter->detail($auditLog)]);
    }

    public function systemData(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/SystemData', [
            'raidPositions' => RaidPosition::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'bozjaItems' => BozjaItem::query()
                ->ordered()
                ->select([
                    'id',
                    'key',
                    'category',
                    'name',
                    'description',
                    'classification',
                    'cache_weight',
                    'icon_url',
                    'sort_order',
                    'is_active',
                ])
                ->selectRaw('source_payload IS NOT NULL as has_source_payload')
                ->get()
                ->map(fn (BozjaItem $item) => [
                    'id' => $item->id,
                    'key' => $item->key,
                    'category' => $item->category,
                    'name' => $item->name,
                    'description' => $item->description,
                    'classification' => $item->classification,
                    'cache_weight' => $item->cache_weight,
                    'icon_url' => $item->icon_url,
                    'sort_order' => $item->sort_order,
                    'is_active' => $item->is_active,
                    'has_source_payload' => (bool) $item->getAttribute('has_source_payload'),
                ]),
        ]);
    }

    private function authorizeAdminAccess(): void
    {
        if (! auth()->user()?->is_admin) {
            abort(403);
        }
    }
}
