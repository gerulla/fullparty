<?php

namespace App\Http\Resources\XivPlugin;

use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\CharacterFieldValue;
use App\Models\OccultProgress;
use App\Models\PhantomJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class XivPluginCharacterResource extends JsonResource
{
    /**
     * @param  Collection<int, CharacterClass>  $characterClasses
     * @param  Collection<int, PhantomJob>  $phantomJobs
     */
    public function __construct(
        $resource,
        private readonly Collection $characterClasses,
        private readonly Collection $phantomJobs,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Character $character */
        $character = $this->resource;
        $classProgress = $character->classes->keyBy('id');
        $phantomJobProgress = $character->phantomJobs->keyBy('id');

        return [
            'id' => $character->id,
            'is_primary' => (bool) $character->is_primary,
            'name' => $character->name,
            'world' => $character->world,
            'datacenter' => $character->datacenter,
            'lodestone_id' => $character->lodestone_id,
            'avatar_url' => $this->imageUrl($character->avatar_url),
            'is_verified' => $character->isVerified(),
            'verified_at' => $character->verified_at?->toIso8601String(),
            'lodestone_refreshed_at' => $character->lodestone_refreshed_at?->toIso8601String(),
            'add_method' => $character->add_method,
            'fields' => $character->fieldValues
                ->filter(fn (CharacterFieldValue $fieldValue): bool => $fieldValue->fieldDefinition !== null)
                ->sortBy(fn (CharacterFieldValue $fieldValue): int => (int) $fieldValue->fieldDefinition?->sort_order)
                ->map(fn (CharacterFieldValue $fieldValue): array => [
                    'key' => $fieldValue->fieldDefinition->slug,
                    'name' => $fieldValue->fieldDefinition->name,
                    'description' => $fieldValue->fieldDefinition->description,
                    'type' => $fieldValue->fieldDefinition->type,
                    'group' => $fieldValue->fieldDefinition->group,
                    'source' => $fieldValue->fieldDefinition->source_type,
                    'is_editable' => (bool) $fieldValue->fieldDefinition->is_editable,
                    'value' => $fieldValue->getCastedValue(),
                ])
                ->values()
                ->all(),
            'classes' => $this->characterClasses
                ->map(function (CharacterClass $characterClass) use ($character, $classProgress): array {
                    $progress = $classProgress->get($characterClass->id);

                    return [
                        'id' => $characterClass->id,
                        'name' => $characterClass->name,
                        'shorthand' => $characterClass->shorthand,
                        'role' => $characterClass->role,
                        'icon_url' => $this->imageUrl($characterClass->icon_url),
                        'flat_icon_url' => $this->imageUrl($characterClass->flaticon_url),
                        'level' => (int) ($progress?->pivot?->level ?? $this->resolveClassLevel($character, $characterClass->shorthand)),
                        'is_preferred' => (bool) ($progress?->pivot?->is_preferred ?? false),
                    ];
                })
                ->values()
                ->all(),
            'occult' => [
                'knowledge_level' => $character->occultProgress?->knowledge_level ?? 0,
                'blood_progress' => $character->occultProgress?->forkedTowerBloodProgress()
                    ?? $this->emptyForkedTowerBloodProgress(),
                'phantom_jobs' => $this->phantomJobs
                    ->map(function (PhantomJob $phantomJob) use ($phantomJobProgress): array {
                        $progress = $phantomJobProgress->get($phantomJob->id);
                        $currentLevel = (int) ($progress?->pivot?->current_level ?? 0);

                        return [
                            'id' => $phantomJob->id,
                            'name' => $phantomJob->name,
                            'icon_url' => $this->imageUrl($phantomJob->icon_url),
                            'black_icon_url' => $this->imageUrl($phantomJob->black_icon_url),
                            'transparent_icon_url' => $this->imageUrl($phantomJob->transparent_icon_url),
                            'sprite_url' => $this->imageUrl($phantomJob->sprite_url),
                            'current_level' => $currentLevel,
                            'max_level' => $phantomJob->max_level,
                            'is_preferred' => (bool) ($progress?->pivot?->is_preferred ?? false),
                            'is_maxed' => $currentLevel >= $phantomJob->max_level,
                        ];
                    })
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function resolveClassLevel(Character $character, string $shorthand): int
    {
        $shorthand = strtolower($shorthand);

        return (int) (
            $this->fieldValue($character, "job.{$shorthand}.level")
            ?? $this->fieldValue($character, "{$shorthand}_level")
            ?? 0
        );
    }

    private function fieldValue(Character $character, string $key): mixed
    {
        return $character->fieldValues
            ->first(fn (CharacterFieldValue $fieldValue): bool => $fieldValue->fieldDefinition?->slug === $key)
            ?->getCastedValue();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyForkedTowerBloodProgress(): array
    {
        return [
            'clears' => 0,
            'data_source' => OccultProgress::DATA_SOURCE_FFLOGS,
            'bosses' => [
                ['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
                ['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
                ['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
                ['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
            ],
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }
}
