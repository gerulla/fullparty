<?php

namespace App\Services\Groups;

use App\Models\BozjaHolster;
use Illuminate\Validation\ValidationException;

class BozjaHolsterPairService
{
    private const MAX_APPLICATION_PAIRS = 50;

    /**
     * @return array<int, array{prepop_id: int, refill_id: int}>
     */
    public function validateApplicationPairs(mixed $value, int $groupId, string $attribute): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_APPLICATION_PAIRS) {
            $this->throwInvalid($attribute);
        }

        $pairs = collect($value)
            ->map(fn (mixed $pair) => $this->normalizePair($pair))
            ->all();

        if (in_array(null, $pairs, true)) {
            $this->throwInvalid($attribute);
        }

        /** @var array<int, array{prepop_id: int, refill_id: int}> $pairs */
        if (collect($pairs)->map(fn (array $pair) => $this->pairKey($pair))->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                $attribute => 'Each holster pair may only be selected once.',
            ]);
        }

        if (! $this->pairsBelongToGroup($pairs, $groupId)) {
            $this->throwInvalid($attribute);
        }

        return $pairs;
    }

    /**
     * @return array<int, array{prepop_id: int, refill_id: int}>|null
     */
    public function filterRememberedPairs(mixed $value, int $groupId): ?array
    {
        try {
            $pairs = $this->validateApplicationPairs($value, $groupId, 'answers');
        } catch (ValidationException) {
            return null;
        }

        return $pairs !== [] ? $pairs : null;
    }

    /**
     * @return array{prepop_id: int, refill_id: int}|null
     */
    public function normalizePair(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $prepopId = filter_var($value['prepop_id'] ?? null, FILTER_VALIDATE_INT);
        $refillId = filter_var($value['refill_id'] ?? null, FILTER_VALIDATE_INT);

        if (! is_int($prepopId) || $prepopId <= 0 || ! is_int($refillId) || $refillId <= 0) {
            return null;
        }

        return [
            'prepop_id' => $prepopId,
            'refill_id' => $refillId,
        ];
    }

    /**
     * @return array<int, array{prepop_id: int, refill_id: int}>
     */
    public function normalizePairs(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $pair) => $this->normalizePair($pair))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array{prepop_id: int, refill_id: int}  $pair
     */
    public function pairKey(array $pair): string
    {
        return $pair['prepop_id'].':'.$pair['refill_id'];
    }

    /**
     * @param  array<int, array{prepop_id: int, refill_id: int}>  $pairs
     */
    private function pairsBelongToGroup(array $pairs, int $groupId): bool
    {
        if ($pairs === []) {
            return true;
        }

        $holsterIds = collect($pairs)
            ->flatMap(fn (array $pair) => [$pair['prepop_id'], $pair['refill_id']])
            ->unique()
            ->values();
        $holsters = BozjaHolster::query()
            ->where('group_id', $groupId)
            ->where('is_active', true)
            ->whereIn('id', $holsterIds)
            ->get(['id', 'type', 'parent_holster_id'])
            ->keyBy('id');

        return collect($pairs)->every(function (array $pair) use ($holsters): bool {
            /** @var BozjaHolster|null $prepop */
            $prepop = $holsters->get($pair['prepop_id']);
            /** @var BozjaHolster|null $refill */
            $refill = $holsters->get($pair['refill_id']);

            return $prepop?->type === BozjaHolster::TYPE_PREPOP
                && $refill?->type === BozjaHolster::TYPE_REFILL
                && (int) $refill->parent_holster_id === (int) $prepop->id;
        });
    }

    private function throwInvalid(string $attribute): never
    {
        throw ValidationException::withMessages([
            $attribute => 'Select a valid Prepop and Refill holster pair.',
        ]);
    }
}
