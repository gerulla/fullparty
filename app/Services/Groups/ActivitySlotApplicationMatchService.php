<?php

namespace App\Services\Groups;

use App\Models\Activity;
use App\Models\ActivityApplicationAnswer;
use App\Models\ActivitySlot;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use Illuminate\Support\Str;

class ActivitySlotApplicationMatchService
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $fieldDefinitionsByActivityId = [];

    public function __construct(
        private readonly ActivitySlotFieldDefinitionBuilder $fieldDefinitionBuilder,
        private readonly BozjaHolsterPairService $bozjaHolsterPairService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(ActivitySlot $slot, ?ActivitySlotAssignment $assignment): array
    {
        $assignment?->loadMissing('application.answers');
        $application = $assignment?->application;

        if (! $application || ! $application->relationLoaded('answers')) {
            return [];
        }

        $answers = $application->answers->keyBy('question_key');
        $usedQuestionKeys = collect();
        $matches = collect($this->fieldDefinitions($slot))
            ->map(function (array $definition) use ($slot, $answers, $usedQuestionKeys): ?array {
                $applicationKey = (string) ($definition['application_key'] ?? '');
                $answer = $applicationKey !== '' ? $answers->get($applicationKey) : null;
                $fieldValue = $slot->fieldValues->firstWhere('field_key', $definition['key'] ?? null);

                if (! $answer instanceof ActivityApplicationAnswer || ! $fieldValue) {
                    return null;
                }

                if (($definition['source'] ?? null) === 'bozja_holsters' && ($definition['type'] ?? null) === 'holster_pair') {
                    $applicationPairs = $this->bozjaHolsterPairService->normalizePairs($answer->value);
                    $slotPair = $this->bozjaHolsterPairService->normalizePair($fieldValue->value);

                    if ($applicationPairs === [] || $slotPair === null) {
                        return null;
                    }

                    $usedQuestionKeys->push($answer->question_key);

                    return $this->matchPayload(
                        answer: $answer,
                        abbreviation: $this->abbreviation($answer, 'bozja_holsters'),
                        matches: collect($applicationPairs)->contains(
                            fn (array $pair): bool => $this->bozjaHolsterPairService->pairKey($pair)
                                === $this->bozjaHolsterPairService->pairKey($slotPair),
                        ),
                    );
                }

                $answerValues = $this->normalizeValues($answer->value);
                $slotValues = $this->normalizeValues($fieldValue->value);

                if ($answerValues === [] || $slotValues === []) {
                    return null;
                }

                $usedQuestionKeys->push($answer->question_key);

                return $this->matchPayload(
                    answer: $answer,
                    abbreviation: $this->abbreviation($answer, (string) ($definition['source'] ?? '')),
                    matches: in_array('any', $answerValues, true)
                        || array_intersect($slotValues, $answerValues) !== [],
                );
            })
            ->filter();

        $partyMatch = $application->answers
            ->first(fn (ActivityApplicationAnswer $answer): bool => ! $usedQuestionKeys->contains($answer->question_key)
                && in_array($answer->question_type, ['single_select', 'multi_select'], true)
                && Str::contains(Str::lower($answer->question_key), 'party'));

        if ($partyMatch instanceof ActivityApplicationAnswer) {
            $answerValues = $this->normalizePartyValues($partyMatch->value);

            if ($answerValues !== []) {
                $matches->prepend($this->matchPayload(
                    answer: $partyMatch,
                    abbreviation: 'P',
                    matches: in_array('any', $answerValues, true)
                        || array_intersect($answerValues, $this->slotPartyValues($slot)) !== [],
                ));
            }
        }

        return $matches->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fieldDefinitions(ActivitySlot $slot): array
    {
        $activity = $slot->relationLoaded('activity')
            ? $slot->activity
            : null;

        if (! $activity instanceof Activity) {
            $activity = Activity::query()
                ->with('activityTypeVersion')
                ->find($slot->activity_id);
        }

        $activityTypeVersion = $activity?->activityTypeVersion;

        if (! $activityTypeVersion instanceof ActivityTypeVersion) {
            return [];
        }

        return $this->fieldDefinitionsByActivityId[$slot->activity_id]
            ??= $this->fieldDefinitionBuilder->build($activityTypeVersion, $activity->group_id);
    }

    /**
     * @return array{key: string, label: array<string, string>, abbreviation: string, matches: bool}
     */
    private function matchPayload(ActivityApplicationAnswer $answer, string $abbreviation, bool $matches): array
    {
        return [
            'key' => (string) $answer->question_key,
            'label' => is_array($answer->question_label)
                ? $answer->question_label
                : ['en' => (string) $answer->question_key],
            'abbreviation' => $abbreviation,
            'matches' => $matches,
        ];
    }

    private function abbreviation(ActivityApplicationAnswer $answer, string $source): string
    {
        return match ($source) {
            'character_classes' => 'C',
            'phantom_jobs' => 'PJ',
            'bozja_holsters' => 'H',
            'raid_positions' => 'RP',
            default => Str::of((string) ($answer->question_label['en'] ?? $answer->question_key))
                ->split('/\s+/')
                ->filter()
                ->take(3)
                ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
                ->implode(''),
        };
    }

    /**
     * @return array<int, string>
     */
    private function normalizeValues(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->flatten()
            ->filter(fn (mixed $entry): bool => is_scalar($entry) && (string) $entry !== '')
            ->map(fn (mixed $entry): string => Str::lower(trim((string) $entry)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizePartyValues(mixed $value): array
    {
        return collect($this->normalizeValues($value))
            ->flatMap(fn (string $entry): array => [$entry, Str::slug($entry)])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function slotPartyValues(ActivitySlot $slot): array
    {
        return collect([
            $slot->group_key,
            ...array_values(is_array($slot->group_label) ? $slot->group_label : []),
        ])
            ->flatMap(fn (mixed $entry): array => [Str::lower(trim((string) $entry)), Str::slug((string) $entry)])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
