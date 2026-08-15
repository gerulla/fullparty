<?php

namespace App\Http\Controllers;

use App\DTOs\QuotaCheck;
use App\Http\Requests\BozjaHolsterRequest;
use App\Http\Resources\BozjaHolsterResource;
use App\Models\BozjaHolster;
use App\Models\Group;
use App\Services\Quotas\QuotaService;
use App\Support\Quotas\QuotaKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class GroupBozjaHolsterController extends Controller
{
    public function __construct(private readonly QuotaService $quotaService) {}

    public function store(BozjaHolsterRequest $request, Group $group): JsonResponse
    {
        $holster = $this->quotaService->run([
            new QuotaCheck(QuotaKey::HOLSTERS_TOTAL, $group),
        ], function () use ($request, $group): BozjaHolster {
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

    public function duplicate(Group $group, BozjaHolster $bozjaHolster): JsonResponse
    {
        $this->assertCanManageHolsters($group);
        $this->assertBelongsToGroup($group, $bozjaHolster);

        $bozjaHolster->loadMissing('items');

        $clone = $this->quotaService->run([
            new QuotaCheck(QuotaKey::HOLSTERS_TOTAL, $group),
        ], function () use ($bozjaHolster): BozjaHolster {
            $clone = $bozjaHolster->replicate([
                'is_default',
            ]);

            $clone->name = $this->cloneLocalizedName($bozjaHolster->name);
            $clone->is_default = false;
            $clone->save();

            $clone->items()->sync($bozjaHolster->items->mapWithKeys(fn ($item) => [
                (int) $item->id => ['quantity' => (int) $item->pivot->quantity],
            ]));

            return $clone;
        });

        return (new BozjaHolsterResource($clone->load('items')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
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

        if ($bozjaHolster->refillHolsters()->exists()) {
            throw ValidationException::withMessages([
                'holster' => 'Delete or reassign this holster\'s refills before deleting it.',
            ]);
        }

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

    /**
     * @param  array<string, mixed>|null  $name
     * @return array<string, string>
     */
    private function cloneLocalizedName(?array $name): array
    {
        $localizedName = collect($name ?? ['en' => 'Untitled Holster'])
            ->map(fn (mixed $value) => filled($value) ? sprintf('%s Copy', (string) $value) : '')
            ->all();

        if (blank($localizedName['en'] ?? null)) {
            $localizedName['en'] = 'Untitled Holster Copy';
        }

        return $localizedName;
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
