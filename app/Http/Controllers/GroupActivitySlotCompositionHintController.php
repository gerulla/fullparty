<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotCompositionHint;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Services\Groups\ActivityManagementRealtimeService;
use App\Services\Groups\ActivityPartyCompositionHintService;
use App\Services\Groups\ActivitySlotSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupActivitySlotCompositionHintController extends Controller
{
    public function update(
        Request $request,
        Group $group,
        Activity $activity,
        ActivitySlot $slot,
        ActivityPartyCompositionHintService $compositionHintService,
        ActivitySlotSerializer $slotSerializer,
        ActivityManagementRealtimeService $activityManagementRealtimeService,
    ): JsonResponse {
        $this->authorize('manageDashboard', [$activity, $group]);

        if ((int) $activity->group_id !== (int) $group->id || (int) $slot->activity_id !== (int) $activity->id) {
            abort(404);
        }

        if ($activity->isArchived()) {
            abort(403);
        }

        $validated = $request->validate([
            'composition_hints' => ['present', 'array', 'max:12'],
            'composition_hints.*.type' => ['required', 'string', Rule::in([
                ActivitySlotCompositionHint::TYPE_ROLE,
                ActivitySlotCompositionHint::TYPE_CLASS,
            ])],
            'composition_hints.*.key' => ['required', 'string', 'max:32'],
        ]);

        $compositionHints = $this->validatedCompositionHints($validated['composition_hints']);
        $slot = $compositionHintService->replaceSlotHints($activity, $slot, $compositionHints);

        $activityManagementRealtimeService->broadcastPatch($activity, [
            'updated_slot_composition_hints' => [[
                'slot_id' => $slot->id,
                'composition_hints' => $slotSerializer->serialize($slot)['composition_hints'],
            ]],
        ]);

        return response()->json([
            'slots' => [
                $slotSerializer->serialize($slot),
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $compositionHints
     * @return array<int, array{type: string, key: string}>
     */
    private function validatedCompositionHints(array $compositionHints): array
    {
        $classKeys = CharacterClass::query()
            ->whereIn('shorthand', collect($compositionHints)
                ->where('type', ActivitySlotCompositionHint::TYPE_CLASS)
                ->pluck('key')
                ->all())
            ->pluck('shorthand')
            ->all();

        $classKeyLookup = array_fill_keys($classKeys, true);

        return collect($compositionHints)
            ->values()
            ->map(function (array $hint) use ($classKeyLookup): array {
                $type = (string) $hint['type'];
                $key = (string) $hint['key'];

                if ($type === ActivitySlotCompositionHint::TYPE_ROLE && ! in_array($key, ['tank', 'healer', 'dps'], true)) {
                    throw ValidationException::withMessages([
                        'composition_hints' => __('errors.role_hint_unsupported'),
                    ]);
                }

                if ($type === ActivitySlotCompositionHint::TYPE_CLASS && ! isset($classKeyLookup[$key])) {
                    throw ValidationException::withMessages([
                        'composition_hints' => __('errors.class_hint_unsupported'),
                    ]);
                }

                return [
                    'type' => $type,
                    'key' => $key,
                ];
            })
            ->all();
    }
}
