<?php

namespace App\Http\Controllers;

use App\Http\Requests\PhantomCompositionRequest;
use App\Models\Group;
use App\Models\PhantomComposition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GroupPhantomCompositionController extends Controller
{
    public function index(Group $group): JsonResponse
    {
        $this->assertCanManagePhantomCompositions($group);

        $compositions = PhantomComposition::query()
            ->where('group_id', $group->id)
            ->where('content_key', PhantomComposition::CONTENT_FORKED_TOWER_BLOOD)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $compositions
                ->map(fn (PhantomComposition $composition) => $this->serialize($composition))
                ->values()
                ->all(),
            'meta' => $this->metadata(),
        ]);
    }

    public function store(PhantomCompositionRequest $request, Group $group): JsonResponse
    {
        $composition = DB::transaction(function () use ($request, $group): PhantomComposition {
            $data = $this->validatedPayload($request);

            if ($data['is_default']) {
                $this->clearDefaultComposition($group);
            }

            return PhantomComposition::query()->create([
                ...$data,
                'group_id' => $group->id,
                'content_key' => PhantomComposition::CONTENT_FORKED_TOWER_BLOOD,
            ]);
        });

        return response()->json([
            'data' => $this->serialize($composition),
            'meta' => $this->metadata(),
        ], Response::HTTP_CREATED);
    }

    public function show(Group $group, PhantomComposition $phantomComposition): JsonResponse
    {
        $this->assertCanManagePhantomCompositions($group);
        $this->assertCompositionBelongsToGroup($group, $phantomComposition);

        return response()->json([
            'data' => $this->serialize($phantomComposition),
            'meta' => $this->metadata(),
        ]);
    }

    public function update(PhantomCompositionRequest $request, Group $group, PhantomComposition $phantomComposition): JsonResponse
    {
        $this->assertCompositionBelongsToGroup($group, $phantomComposition);

        $composition = DB::transaction(function () use ($request, $group, $phantomComposition): PhantomComposition {
            $data = $this->validatedPayload($request);

            if ($data['is_default']) {
                $this->clearDefaultComposition($group, $phantomComposition);
            }

            $phantomComposition->update($data);

            return $phantomComposition->refresh();
        });

        return response()->json([
            'data' => $this->serialize($composition),
            'meta' => $this->metadata(),
        ]);
    }

    public function destroy(Group $group, PhantomComposition $phantomComposition): Response
    {
        $this->assertCanManagePhantomCompositions($group);
        $this->assertCompositionBelongsToGroup($group, $phantomComposition);

        $phantomComposition->delete();

        return response()->noContent();
    }

    public function reorder(Request $request, Group $group): JsonResponse
    {
        $this->assertCanManagePhantomCompositions($group);

        $validated = $request->validate([
            'composition_ids' => ['required', 'array'],
            'composition_ids.*' => ['integer', 'distinct'],
        ]);

        $compositionIds = array_map('intval', $validated['composition_ids']);
        $compositionCount = PhantomComposition::query()
            ->where('group_id', $group->id)
            ->where('content_key', PhantomComposition::CONTENT_FORKED_TOWER_BLOOD)
            ->whereIn('id', $compositionIds)
            ->count();

        if ($compositionCount !== count($compositionIds)) {
            throw ValidationException::withMessages([
                'composition_ids' => 'All composition IDs must belong to this group and content page.',
            ]);
        }

        DB::transaction(function () use ($compositionIds): void {
            foreach ($compositionIds as $sortOrder => $compositionId) {
                PhantomComposition::query()
                    ->whereKey($compositionId)
                    ->update(['sort_order' => $sortOrder]);
            }
        });

        $compositions = PhantomComposition::query()
            ->where('group_id', $group->id)
            ->where('content_key', PhantomComposition::CONTENT_FORKED_TOWER_BLOOD)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $compositions
                ->map(fn (PhantomComposition $composition) => $this->serialize($composition))
                ->values()
                ->all(),
            'meta' => $this->metadata(),
        ]);
    }

    private function assertCanManagePhantomCompositions(Group $group): void
    {
        $group->loadMissing('memberships');

        abort_unless($group->hasModeratorAccess(auth()->id()), 403);
    }

    private function assertCompositionBelongsToGroup(Group $group, PhantomComposition $composition): void
    {
        abort_unless((int) $composition->group_id === (int) $group->id, 404);
        abort_unless($composition->content_key === PhantomComposition::CONTENT_FORKED_TOWER_BLOOD, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(PhantomCompositionRequest $request): array
    {
        $validated = $request->validated();

        return [
            'name' => (string) $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_default' => (bool) $validated['is_default'],
            'is_active' => (bool) $validated['is_active'],
            'sort_order' => (int) $validated['sort_order'],
            'rules' => array_values($validated['rules']),
        ];
    }

    private function clearDefaultComposition(Group $group, ?PhantomComposition $except = null): void
    {
        PhantomComposition::query()
            ->where('group_id', $group->id)
            ->where('content_key', PhantomComposition::CONTENT_FORKED_TOWER_BLOOD)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->update(['is_default' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PhantomComposition $composition): array
    {
        return [
            'id' => $composition->id,
            'group_id' => $composition->group_id,
            'content_key' => $composition->content_key,
            'name' => $composition->name,
            'description' => $composition->description,
            'is_default' => $composition->is_default,
            'is_active' => $composition->is_active,
            'sort_order' => $composition->sort_order,
            'rules' => $composition->rules ?? [],
            'created_at' => $composition->created_at?->toIso8601String(),
            'updated_at' => $composition->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        $contentKey = PhantomComposition::CONTENT_FORKED_TOWER_BLOOD;

        return [
            'content_key' => $contentKey,
            'rule_types' => PhantomComposition::ruleTypes(),
            'severities' => PhantomComposition::severities(),
            'comparisons' => PhantomComposition::comparisons(),
            'scope_types' => PhantomComposition::scopeTypes(),
            'states' => PhantomComposition::states(),
            'slot_groups' => PhantomComposition::slotGroupsForContent($contentKey),
            'default_group_sets' => PhantomComposition::defaultGroupSetsForContent($contentKey),
        ];
    }
}
