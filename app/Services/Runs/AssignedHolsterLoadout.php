<?php

namespace App\Services\Runs;

use App\Models\ActivitySlotFieldValue;
use App\Models\BozjaHolster;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\BozjaHolsterPairService;
use App\Services\Groups\Resources\ResourceHolsterService;
use App\Services\Groups\Resources\ResourceReaderService;

class AssignedHolsterLoadout
{
    public function __construct(
        private readonly BozjaHolsterPairService $pairs,
        private readonly ResourceHolsterService $holsters,
        private readonly ResourceReaderService $reader,
    ) {}

    public function forAssignment(Group $group, ActivitySlotFieldValue $field, ?User $viewer): array
    {
        $value = $field->value;
        $pair = $this->pairs->normalizePair($value);
        $available = BozjaHolster::where('group_id', $group->id)->where('is_active', true)->whereNull('moderation_hidden_at')->with('items');

        // Older slot schemas selected a single holster, which could itself be a refill.
        if ($pair === null) {
            $id = is_array($value) ? ($value['id'] ?? $value['key'] ?? null) : $value;
            abort_unless(filter_var($id, FILTER_VALIDATE_INT), 404);
            $selected = (clone $available)->findOrFail($id);
            $pair = $selected->type === BozjaHolster::TYPE_REFILL
                ? ['prepop_id' => $selected->parent_holster_id, 'refill_id' => $selected->id]
                : ['prepop_id' => $selected->id, 'refill_id' => null];
        }

        $prepop = (clone $available)->where('type', BozjaHolster::TYPE_PREPOP)->findOrFail($pair['prepop_id']);
        $refill = $pair['refill_id'] === null ? null : $available->where('type', BozjaHolster::TYPE_REFILL)
            ->where('parent_holster_id', $prepop->id)->findOrFail($pair['refill_id']);

        return [
            'prepop' => $this->holsters->loadout($prepop),
            'refills' => $refill ? [$this->holsters->loadout($refill)] : [],
            'guide_url' => $this->reader->holsterGuideUrl($group, $prepop, $viewer),
        ];
    }
}
