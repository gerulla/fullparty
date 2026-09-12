<?php

namespace App\Services\Groups;

use App\Models\BozjaHolster;
use Illuminate\Validation\ValidationException;

class BozjaHolsterPairService
{
    private const MAX_APPLICATION_PAIRS = 50;

    /**
     * @return array<int, array{prepop_id: int, refill_id: int|null}>
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

        /** @var array<int, array{prepop_id: int, refill_id: int|null}> $pairs */
        if (collect($pairs)->map(fn (array $pair) => $this->pairKey($pair))->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                $attribute => __('holsters.duplicate_selection'),
            ]);
        }

        if (! $this->pairsBelongToGroup($pairs, $groupId)) {
            $this->throwInvalid($attribute);
        }

        return $pairs;
    }

    /**
     * @return array<int, array{prepop_id: int, refill_id: int|null}>|null
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
     * @return array{prepop_id: int, refill_id: int|null}|null
     */
    public function normalizePair(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $prepopId = filter_var($value['prepop_id'] ?? null, FILTER_VALIDATE_INT);
        $rawRefillId = $value['refill_id'] ?? null;
        $refillId = $rawRefillId === null || $rawRefillId === '' ? null : filter_var($rawRefillId, FILTER_VALIDATE_INT);

        if (! is_int($prepopId) || $prepopId <= 0 || ($refillId !== null && (! is_int($refillId) || $refillId <= 0))) {
            return null;
        }

        return [
            'prepop_id' => $prepopId,
            'refill_id' => $refillId,
        ];
    }

    /**
     * @return array<int, array{prepop_id: int, refill_id: int|null}>
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
     * @param  array{prepop_id: int, refill_id: int|null}  $pair
     */
    public function pairKey(array $pair): string
    {
        return $pair['prepop_id'].':'.$pair['refill_id'];
    }

    /** @param array{prepop_id: int, refill_id: int|null} $pair */
    public function pairIsAvailableInOptions(array $pair, array $options): bool
    {
        $options = collect($options)->keyBy(fn (array $option) => (string) ($option['key'] ?? ''));
        $prepop = $options->get((string) $pair['prepop_id']);
        if (($prepop['meta']['holster_type'] ?? null) !== BozjaHolster::TYPE_PREPOP) {
            return false;
        }
        $refills = $options->filter(fn (array $option) => ($option['meta']['holster_type'] ?? null) === BozjaHolster::TYPE_REFILL
            && (int) ($option['meta']['parent_holster_id'] ?? 0) === $pair['prepop_id']);

        return $pair['refill_id'] === null ? $refills->isEmpty() : $refills->has((string) $pair['refill_id']);
    }

    /**
     * @param  array<int, array{prepop_id: int, refill_id: int|null}>  $pairs
     */
    private function pairsBelongToGroup(array $pairs, int $groupId): bool
    {
        if ($pairs === []) {
            return true;
        }

        $holsterIds = collect($pairs)
            ->flatMap(fn (array $pair) => [$pair['prepop_id'], $pair['refill_id']])
            ->filter()
            ->unique()
            ->values();
        $holsters = BozjaHolster::query()
            ->where('group_id', $groupId)
            ->where('is_active', true)
            ->whereIn('id', $holsterIds)
            ->select(['id', 'type', 'parent_holster_id'])
            ->withCount(['refillHolsters as active_refill_count' => fn ($query) => $query
                ->where('group_id', $groupId)->where('is_active', true)->where('type', BozjaHolster::TYPE_REFILL)])
            ->get()
            ->keyBy('id');

        return collect($pairs)->every(function (array $pair) use ($holsters): bool {
            /** @var BozjaHolster|null $prepop */
            $prepop = $holsters->get($pair['prepop_id']);
            if ($prepop?->type !== BozjaHolster::TYPE_PREPOP) {
                return false;
            }
            if ($pair['refill_id'] === null) {
                return (int) $prepop->active_refill_count === 0;
            }
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
            $attribute => __('holsters.invalid_selection'),
        ]);
    }
}
