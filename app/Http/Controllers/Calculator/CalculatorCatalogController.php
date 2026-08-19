<?php

namespace App\Http\Controllers\Calculator;

use App\Http\Controllers\Controller;
use App\Models\CalculatorAction;
use App\Models\CalculatorTrait;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CalculatorCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        $actions = CalculatorAction::query()
            ->active()
            ->orderBy('role')
            ->orderBy('job_name')
            ->orderBy('unlock_level')
            ->orderBy('source_id')
            ->get();

        $traits = CalculatorTrait::query()
            ->active()
            ->orderBy('role')
            ->orderBy('job_name')
            ->orderBy('unlock_level')
            ->orderBy('source_id')
            ->get();

        return response()->json([
            'jobs' => $this->jobs($actions, $traits, $locale)->values(),
        ]);
    }

    public function action(Request $request, CalculatorAction $calculatorAction): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'id' => $calculatorAction->id,
            'source_id' => $calculatorAction->source_id,
            'name' => $calculatorAction->localizedName($locale),
            'description' => $calculatorAction->localizedDescription($locale),
            'description_macro' => $this->localizedString($calculatorAction, 'description_macro_translations', 'description_macro', $locale),
            'effects' => $calculatorAction->localizedEffects($locale),
            'role' => $calculatorAction->role,
            'job' => [
                'id' => $calculatorAction->job_id,
                'name' => $calculatorAction->job_name,
                'abbreviation' => $calculatorAction->job_abbreviation,
            ],
            'is_phantom_action' => $calculatorAction->is_phantom_action,
            'unlock_level' => $calculatorAction->unlock_level,
            'icon' => [
                'id' => $calculatorAction->icon_id,
                'file' => $calculatorAction->icon_file,
                'url' => $calculatorAction->icon_url,
            ],
            'action_category' => [
                'id' => $calculatorAction->action_category_id,
                'name' => $calculatorAction->action_category_name,
            ],
            'attack_type' => [
                'id' => $calculatorAction->attack_type_id,
                'name' => $calculatorAction->attack_type_name,
            ],
            'timing' => [
                'cast_seconds' => $calculatorAction->timing_cast_seconds,
                'recast_seconds' => $calculatorAction->timing_recast_seconds,
                'extra_cast_seconds' => $calculatorAction->timing_extra_cast_seconds,
                'cooldown_group' => $calculatorAction->timing_cooldown_group,
                'additional_cooldown_group' => $calculatorAction->timing_additional_cooldown_group,
                'max_charges' => $calculatorAction->timing_max_charges,
            ],
            'costs' => [
                'primary' => [
                    'type_id' => $calculatorAction->cost_primary_type_id,
                    'value' => $calculatorAction->cost_primary_value,
                ],
                'secondary' => [
                    'type_id' => $calculatorAction->cost_secondary_type_id,
                    'value' => $calculatorAction->cost_secondary_value,
                ],
            ],
            'range' => [
                'target_yalms' => $calculatorAction->range_target_yalms,
                'effect_yalms' => $calculatorAction->range_effect_yalms,
                'cast_type' => $calculatorAction->range_cast_type,
            ],
            'targeting' => [
                'self' => $calculatorAction->targeting_self,
                'party' => $calculatorAction->targeting_party,
                'alliance' => $calculatorAction->targeting_alliance,
                'hostile' => $calculatorAction->targeting_hostile,
                'ally' => $calculatorAction->targeting_ally,
                'own_pet' => $calculatorAction->targeting_own_pet,
                'party_pet' => $calculatorAction->targeting_party_pet,
                'is_area' => $calculatorAction->targeting_is_area,
                'dead_target_behavior' => $calculatorAction->targeting_dead_target_behavior,
                'requires_line_of_sight' => $calculatorAction->targeting_requires_line_of_sight,
                'requires_facing_target' => $calculatorAction->targeting_requires_facing_target,
            ],
            'combo' => [
                'previous_action_id' => $calculatorAction->combo_previous_action_id,
                'preserves_combo' => $calculatorAction->combo_preserves_combo,
            ],
            'statuses' => [
                'gain_self' => $calculatorAction->status_gain_self_id === null ? null : [
                    'id' => $calculatorAction->status_gain_self_id,
                    'name' => $calculatorAction->status_gain_self_name,
                    'description' => $calculatorAction->status_gain_self_description,
                    'icon_id' => $calculatorAction->status_gain_self_icon_id,
                    'max_stacks' => $calculatorAction->status_gain_self_max_stacks,
                ],
                'proc' => $calculatorAction->status_proc_id === null ? null : [
                    'id' => $calculatorAction->status_proc_id,
                    'status' => [
                        'id' => $calculatorAction->status_proc_status_id,
                        'name' => $calculatorAction->status_proc_status_name,
                        'description' => $calculatorAction->status_proc_status_description,
                        'icon_id' => $calculatorAction->status_proc_status_icon_id,
                        'max_stacks' => $calculatorAction->status_proc_status_max_stacks,
                    ],
                ],
            ],
            'metadata' => [
                'aspect_id' => $calculatorAction->metadata_aspect_id,
                'behavior_type' => $calculatorAction->metadata_behavior_type,
                'class_job_category_id' => $calculatorAction->metadata_class_job_category_id,
                'source_class_job_id' => $calculatorAction->metadata_source_class_job_id,
                'is_role_action' => $calculatorAction->metadata_is_role_action,
                'is_player_action' => $calculatorAction->metadata_is_player_action,
                'is_derived_action' => $calculatorAction->metadata_is_derived_action,
                'equivalence_group' => $calculatorAction->metadata_equivalence_group,
            ],
        ]);
    }

    public function trait(Request $request, CalculatorTrait $calculatorTrait): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'id' => $calculatorTrait->id,
            'source_id' => $calculatorTrait->source_id,
            'name' => $calculatorTrait->localizedName($locale),
            'description' => $calculatorTrait->localizedDescription($locale),
            'description_macro' => $this->localizedString($calculatorTrait, 'description_macro_translations', 'description_macro', $locale),
            'effects' => $calculatorTrait->localizedEffects($locale),
            'role' => $calculatorTrait->role,
            'job' => [
                'id' => $calculatorTrait->job_id,
                'name' => $calculatorTrait->job_name,
                'abbreviation' => $calculatorTrait->job_abbreviation,
            ],
            'unlock_level' => $calculatorTrait->unlock_level,
            'value' => $calculatorTrait->value,
            'icon' => [
                'id' => $calculatorTrait->icon_id,
                'file' => $calculatorTrait->icon_file,
                'url' => $calculatorTrait->icon_url,
            ],
            'class_job_category_id' => $calculatorTrait->class_job_category_id,
            'source_class_job_id' => $calculatorTrait->source_class_job_id,
            'is_phantom_trait' => $calculatorTrait->is_phantom_trait,
        ]);
    }

    /**
     * @param  EloquentCollection<int, CalculatorAction>  $actions
     * @param  EloquentCollection<int, CalculatorTrait>  $traits
     * @return Collection<int, array<string, mixed>>
     */
    private function jobs(EloquentCollection $actions, EloquentCollection $traits, string $locale): Collection
    {
        $jobs = collect();

        foreach ($actions->groupBy(fn (CalculatorAction $action) => $this->jobKey($action->role, $action->job_id)) as $jobActions) {
            /** @var CalculatorAction $firstAction */
            $firstAction = $jobActions->first();
            $jobs->put($this->jobKey($firstAction->role, $firstAction->job_id), [
                'role' => $firstAction->role,
                'id' => $firstAction->job_id,
                'name' => $firstAction->job_name,
                'abbreviation' => $firstAction->job_abbreviation,
                'classIconPath' => $this->classIconUrl($firstAction->source_path),
                'actions' => $jobActions
                    ->map(fn (CalculatorAction $action) => $this->catalogAction($action, $locale))
                    ->values()
                    ->all(),
                'traits' => [],
            ]);
        }

        foreach ($traits->groupBy(fn (CalculatorTrait $trait) => $this->jobKey($trait->role, $trait->job_id)) as $jobTraits) {
            /** @var CalculatorTrait $firstTrait */
            $firstTrait = $jobTraits->first();
            $jobKey = $this->jobKey($firstTrait->role, $firstTrait->job_id);
            $job = $jobs->get($jobKey) ?? [
                'role' => $firstTrait->role,
                'id' => $firstTrait->job_id,
                'name' => $firstTrait->job_name,
                'abbreviation' => $firstTrait->job_abbreviation,
                'classIconPath' => $this->classIconUrl($firstTrait->source_path),
                'actions' => [],
                'traits' => [],
            ];

            $job['traits'] = $jobTraits
                ->map(fn (CalculatorTrait $trait) => $this->catalogTrait($trait, $locale))
                ->values()
                ->all();

            $jobs->put($jobKey, $job);
        }

        return $jobs->sortBy([
            fn (array $job) => $this->roleSort($job['role'] ?? ''),
            fn (array $job) => (string) ($job['name'] ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogAction(CalculatorAction $action, string $locale): array
    {
        return [
            'id' => $action->id,
            'sourceId' => $action->source_id,
            'name' => $action->localizedName($locale),
            'unlockLevel' => $action->unlock_level,
            'iconPath' => $action->icon_url,
            'detailUrl' => route('calculator.actions.show', $action, absolute: false),
            'actionCategory' => $action->action_category_name,
            'actionCategoryId' => $action->action_category_id,
            'castSeconds' => $action->timing_cast_seconds,
            'recastSeconds' => $action->timing_recast_seconds,
            'maxCharges' => $action->timing_max_charges,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogTrait(CalculatorTrait $trait, string $locale): array
    {
        return [
            'id' => $trait->id,
            'sourceId' => $trait->source_id,
            'name' => $trait->localizedName($locale),
            'unlockLevel' => $trait->unlock_level,
            'iconPath' => $trait->icon_url,
            'detailUrl' => route('calculator.traits.show', $trait, absolute: false),
        ];
    }

    private function locale(Request $request): string
    {
        $locale = str($request->query('locale', app()->getLocale()))->before('-')->value();

        return in_array($locale, ['en', 'de', 'fr', 'ja'], true) ? $locale : 'en';
    }

    private function localizedString(CalculatorAction|CalculatorTrait $model, string $translationsAttribute, string $fallbackAttribute, string $locale): ?string
    {
        $translations = $model->{$translationsAttribute} ?? [];

        return $translations[$locale] ?? $translations['en'] ?? $model->{$fallbackAttribute};
    }

    private function jobKey(string $role, int $jobId): string
    {
        return "{$role}:{$jobId}";
    }

    private function classIconUrl(?string $sourcePath): ?string
    {
        if ($sourcePath === null) {
            return null;
        }

        $basePath = preg_replace('#/(Abilities|Traits)/.+$#', '', $sourcePath);

        if (! is_string($basePath) || $basePath === $sourcePath) {
            return null;
        }

        return $this->publicAssetUrl($basePath.'/class-icon.png');
    }

    private function publicAssetUrl(string $path): string
    {
        return '/'.collect(explode('/', $path))
            ->map(fn (string $segment) => rawurlencode($segment))
            ->implode('/');
    }

    private function roleSort(string $role): int
    {
        return match ($role) {
            'Tank' => 10,
            'Healer' => 20,
            'Melee DPS' => 30,
            'Physical Ranged DPS' => 40,
            'Magical Ranged DPS' => 50,
            'Phantom Jobs' => 60,
            default => 99,
        };
    }
}
