<?php

namespace App\Http\Controllers;

use App\Http\Requests\BozjaHolsterRequest;
use App\Http\Resources\BozjaHolsterResource;
use App\Models\BozjaHolster;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class GroupBozjaHolsterController extends Controller
{
    public function store(BozjaHolsterRequest $request, Group $group): JsonResponse
    {
        $holster = DB::transaction(function () use ($request, $group): BozjaHolster {
            $holster = $group->bozjaHolsters()->create($request->safe()->except('items'));
            $this->syncItems($holster, $request->validated('items'));

            return $holster;
        });

        return (new BozjaHolsterResource($holster->load('items')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(BozjaHolsterRequest $request, Group $group, BozjaHolster $bozjaHolster): JsonResponse
    {
        $this->assertBelongsToGroup($group, $bozjaHolster);

        DB::transaction(function () use ($request, $bozjaHolster): void {
            $bozjaHolster->update($request->safe()->except('items'));
            $this->syncItems($bozjaHolster, $request->validated('items'));
        });

        return (new BozjaHolsterResource($bozjaHolster->refresh()->load('items')))->response();
    }

    public function updateStatus(Request $request, Group $group, BozjaHolster $bozjaHolster): JsonResponse
    {
        $this->assertCanManageHolsters($group);
        $this->assertBelongsToGroup($group, $bozjaHolster);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $bozjaHolster->update([
            'is_active' => (bool) $validated['is_active'],
        ]);

        return (new BozjaHolsterResource($bozjaHolster->refresh()->load('items')))->response();
    }

    public function makeDefault(Group $group, BozjaHolster $bozjaHolster): JsonResponse
    {
        $this->assertCanManageHolsters($group);
        $this->assertBelongsToGroup($group, $bozjaHolster);

        DB::transaction(function () use ($group, $bozjaHolster): void {
            $group->bozjaHolsters()
                ->whereKeyNot($bozjaHolster->id)
                ->update(['is_default' => false]);
            $bozjaHolster->update(['is_default' => true]);
        });

        return (new BozjaHolsterResource($bozjaHolster->refresh()->load('items')))->response();
    }

    public function destroy(Group $group, BozjaHolster $bozjaHolster): Response
    {
        $this->assertCanManageHolsters($group);
        $this->assertBelongsToGroup($group, $bozjaHolster);

        $bozjaHolster->delete();

        return response()->noContent();
    }

    /** @param array<int, array{id: int, quantity: int}> $items */
    private function syncItems(BozjaHolster $holster, array $items): void
    {
        $holster->items()->sync(collect($items)->mapWithKeys(fn (array $item) => [
            (int) $item['id'] => ['quantity' => (int) $item['quantity']],
        ]));
    }

    private function assertBelongsToGroup(Group $group, BozjaHolster $holster): void
    {
        abort_unless((int) $holster->group_id === (int) $group->id, 404);
    }

    private function assertCanManageHolsters(Group $group): void
    {
        $group->loadMissing('memberships');

        abort_unless($group->hasModeratorAccess(auth()->id()), 403);
    }
}
