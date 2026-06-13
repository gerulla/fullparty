<?php

namespace Database\Seeders;

use App\Models\ActivityTag;
use App\Models\ActivityType;
use App\Models\User;
use App\Support\ActivityCompositionPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UltimateActivityTypeSeeder extends Seeder
{
    /**
     * Seed ultimate raid activity types.
     */
    public function run(): void
    {
        $publisherId = User::query()->value('id');

        foreach ($this->activityTypes() as $activityTypeData) {
            DB::transaction(function () use ($activityTypeData, $publisherId) {
                $activityType = ActivityType::query()->firstOrNew([
                    'slug' => $activityTypeData['slug'],
                ]);

                $activityType->fill([
                    'draft_name' => $activityTypeData['draft_name'],
                    'draft_description' => $activityTypeData['draft_description'],
                    'draft_small_image_url' => $activityTypeData['draft_small_image_url'] ?? null,
                    'draft_banner_image_url' => $activityTypeData['draft_banner_image_url'] ?? null,
                    'draft_difficulty' => $activityTypeData['draft_difficulty'] ?? ActivityType::DIFFICULTY_ULTIMATE,
                    'draft_default_min_item_level' => $activityTypeData['draft_default_min_item_level'] ?? null,
                    'draft_layout_schema' => $activityTypeData['draft_layout_schema'],
                    'draft_slot_schema' => $activityTypeData['draft_slot_schema'],
                    'draft_application_schema' => $activityTypeData['draft_application_schema'],
                    'draft_roster_summary_presets' => $activityTypeData['draft_roster_summary_presets'] ?? [],
                    'draft_progress_schema' => $activityTypeData['draft_progress_schema'],
                    'draft_bench_size' => $activityTypeData['draft_bench_size'] ?? 0,
                    'draft_prog_points' => $activityTypeData['draft_prog_points'] ?? [],
                    'draft_fflogs_zone_id' => $activityTypeData['draft_fflogs_zone_id'] ?? null,
                    'is_active' => true,
                    'created_by_user_id' => $activityType->exists
                        ? $activityType->created_by_user_id
                        : $publisherId,
                    'current_published_version_id' => null,
                ]);
                $activityType->save();

                $activityType->versions()->delete();

                $version = $activityType->versions()->create([
                    'version' => 1,
                    'name' => $activityTypeData['draft_name'],
                    'description' => $activityTypeData['draft_description'],
                    'small_image_url' => $activityTypeData['draft_small_image_url'] ?? null,
                    'banner_image_url' => $activityTypeData['draft_banner_image_url'] ?? null,
                    'difficulty' => $activityTypeData['draft_difficulty'] ?? ActivityType::DIFFICULTY_ULTIMATE,
                    'default_min_item_level' => $activityTypeData['draft_default_min_item_level'] ?? null,
                    'layout_schema' => $activityTypeData['draft_layout_schema'],
                    'slot_schema' => $activityTypeData['draft_slot_schema'],
                    'application_schema' => $activityTypeData['draft_application_schema'],
                    'roster_summary_presets' => $activityTypeData['draft_roster_summary_presets'] ?? [],
                    'progress_schema' => $activityTypeData['draft_progress_schema'],
                    'bench_size' => $activityTypeData['draft_bench_size'] ?? 0,
                    'prog_points' => $activityTypeData['draft_prog_points'] ?? [],
                    'fflogs_zone_id' => $activityTypeData['draft_fflogs_zone_id'] ?? null,
                    'published_by_user_id' => $publisherId,
                    'published_at' => now(),
                ]);

                $activityType->forceFill([
                    'current_published_version_id' => $version->id,
                ])->save();

                $this->syncTags($activityType, $activityTypeData['tags'] ?? []);
            });
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activityTypes(): array
    {
        return array_map(
            fn (array $ultimate): array => $this->ultimateActivityType($ultimate),
            $this->ultimateBlueprints(),
        );
    }

    /**
     * Add more ultimate records here as their data is gathered.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ultimateBlueprints(): array
    {
        return [
            $this->ultimateFight(
                slug: 'the-unending-coil-of-bahamut-ultimate',
                name: 'The Unending Coil of Bahamut (Ultimate)',
                shortName: 'UCOB',
                image: 'ultimates/the-unending-coil-of-bahamut-ultimate.png',
                minItemLevel: 345,
                description: 'The wandering minstrel imagines the hidden trials surrounding the Seventh Umbral Calamity, transporting you back to a struggle against the elder primal Bahamut.',
                fflogsZoneId: 59,
                encounters: [
                    [
                        'key' => 'ucob',
                        'name' => 'The Unending Coil of Bahamut',
                        'encounter_id' => 1073,
                        'phases' => [
                            ['key' => 'p1', 'name' => 'Twintania', 'phase_id' => 1],
                            ['key' => 'p2', 'name' => 'Nael Deus Darnus', 'phase_id' => 2],
                            ['key' => 'p3', 'name' => 'Bahamut Prime', 'phase_id' => 3],
                            ['key' => 'p4', 'name' => 'Adds', 'phase_id' => 4],
                            ['key' => 'p5', 'name' => 'Golden Bahamut', 'phase_id' => 5],
                        ],
                    ],
                ],
            ),
            $this->ultimateFight(
                slug: 'the-weapons-refrain-ultimate',
                name: 'The Weapon\'s Refrain (Ultimate)',
                shortName: 'UWU',
                image: 'ultimates/the-weapons-refrain-ultimate.png',
                minItemLevel: 375,
                description: 'The wandering minstrel embellishes your triumph over the Ultima Weapon into an epic battle that barely resembles the original tale.',
                fflogsZoneId: 59,
                encounters: [
                    [
                        'key' => 'uwu',
                        'name' => 'The Weapon\'s Refrain',
                        'encounter_id' => 1074,
                        'phases' => [
                            ['key' => 'p1', 'name' => 'Garuda', 'phase_id' => 1],
                            ['key' => 'p2', 'name' => 'Ifrit', 'phase_id' => 2],
                            ['key' => 'p3', 'name' => 'Titan', 'phase_id' => 3],
                            ['key' => 'p4', 'name' => 'Lahabrea', 'phase_id' => 4],
                            ['key' => 'p5', 'name' => 'The Ultima Weapon', 'phase_id' => 5],
                        ],
                    ],
                ],
            ),
            $this->ultimateFight(
                slug: 'the-epic-of-alexander-ultimate',
                name: 'The Epic of Alexander (Ultimate)',
                shortName: 'TEA',
                image: 'ultimates/the-epic-of-alexander-ultimate.png',
                minItemLevel: 475,
                description: 'Inspired by the legend of a mechanical god and a mortal soul, the wandering minstrel transforms Alexander\'s tale into a ballad of hope and despair.',
                fflogsZoneId: 59,
                encounters: [
                    [
                        'key' => 'tea',
                        'name' => 'The Epic of Alexander',
                        'encounter_id' => 1075,
                        'phases' => [
                            ['key' => 'p1', 'name' => 'Living Liquid', 'phase_id' => 1],
                            ['key' => 'p2', 'name' => 'Brute Justice and Cruise Chaser', 'phase_id' => 2],
                            ['key' => 'p3', 'name' => 'Alexander Prime', 'phase_id' => 3],
                            ['key' => 'p4', 'name' => 'Perfect Alexander', 'phase_id' => 4],
                        ],
                    ],
                ],
            ),
            $this->ultimateFight(
                slug: 'dragonsongs-reprise-ultimate',
                name: 'Dragonsong\'s Reprise (Ultimate)',
                shortName: 'DSR',
                image: 'ultimates/dragonsongs-reprise-ultimate.png',
                minItemLevel: 605,
                description: 'The wandering minstrel invites you to imagine an alternate conclusion to the Dragonsong War, one where a dear comrade is spared his tragic fate.',
                fflogsZoneId: 59,
                encounters: [
                    [
                        'key' => 'dsr',
                        'name' => 'Dragonsong\'s Reprise',
                        'encounter_id' => 1076,
                        'phases' => [
                            ['key' => 'p1', 'name' => 'Sers Adelphel, Grinnaux, and Charibert', 'phase_id' => 1],
                            ['key' => 'p2', 'name' => 'King Thordan and His Knights Twelve', 'phase_id' => 2],
                            ['key' => 'p3', 'name' => 'Nidhogg', 'phase_id' => 3],
                            ['key' => 'p4', 'name' => 'The Eyes of Nidhogg', 'phase_id' => 4],
                            ['key' => 'p5', 'name' => 'King Thordan II', 'phase_id' => 5],
                            ['key' => 'p6', 'name' => 'Nidhogg and Hraesvelgr', 'phase_id' => 6],
                            ['key' => 'p7', 'name' => 'The Dragon King', 'phase_id' => 7],
                        ],
                    ],
                ],
            ),
            $this->ultimateFight(
                slug: 'the-omega-protocol-ultimate',
                name: 'The Omega Protocol (Ultimate)',
                shortName: 'TOP',
                image: 'ultimates/the-omega-protocol-ultimate.png',
                minItemLevel: 635,
                description: 'The minstrel asks you to imagine what might have happened if Omega\'s relentless testing had continued to its ultimate conclusion.',
                fflogsZoneId: 59,
                encounters: [
                    [
                        'key' => 'top',
                        'name' => 'The Omega Protocol',
                        'encounter_id' => 1077,
                        'phases' => [
                            ['key' => 'p1', 'name' => 'Omega', 'phase_id' => 1],
                            ['key' => 'p2', 'name' => 'Omega-M/F', 'phase_id' => 2],
                            ['key' => 'p3', 'name' => 'Omega', 'phase_id' => 3],
                            ['key' => 'p4', 'name' => 'Blue Screen', 'phase_id' => 4],
                            ['key' => 'p5', 'name' => 'Run: Dynamis', 'phase_id' => 5],
                            ['key' => 'p6', 'name' => 'Alpha Omega', 'phase_id' => 6],
                        ],
                    ],
                ],
            ),
            $this->ultimateFight(
                slug: 'futures-rewritten-ultimate',
                name: 'Futures Rewritten (Ultimate)',
                shortName: 'FRU',
                image: 'ultimates/futures-rewritten-ultimate.png',
                minItemLevel: 735,
                description: 'The wandering minstrel imagines an alternate future surrounding the Flood of Light and the fate of Ryne, the young Oracle of Light.',
                fflogsZoneId: 65,
                encounters: [
                    [
                        'key' => 'fru',
                        'name' => 'Futures Rewritten',
                        'encounter_id' => 1079,
                        'phases' => [
                            ['key' => 'p1', 'name' => 'Fatebreaker', 'phase_id' => 1],
                            ['key' => 'p2', 'name' => 'Usurper of Frost', 'phase_id' => 2],
                            ['key' => 'p3', 'name' => 'Oracle of Darkness', 'phase_id' => 3],
                            ['key' => 'p4', 'name' => 'Enter the Dragon', 'phase_id' => 4],
                            ['key' => 'p5', 'name' => 'Pandora', 'phase_id' => 5],
                        ],
                    ],
                ],
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $encounters
     * @return array<string, mixed>
     */
    private function ultimateFight(
        string $slug,
        string $name,
        string $shortName,
        string $image,
        int $minItemLevel,
        string $description,
        int $fflogsZoneId,
        array $encounters,
    ): array {
        return [
            'slug' => $slug,
            'name' => $this->localizedSame($name),
            'short_name' => $shortName,
            'description' => $this->localizedSame("{$shortName}: {$description}"),
            'image' => $image,
            'default_min_item_level' => $minItemLevel,
            'fflogs_zone_id' => $fflogsZoneId,
            'fflogs_encounters' => $encounters,
            'tags' => [$shortName],
        ];
    }

    /**
     * @param  array<string, mixed>  $ultimate
     * @return array<string, mixed>
     */
    private function ultimateActivityType(array $ultimate): array
    {
        return [
            'slug' => (string) $ultimate['slug'],
            'draft_name' => $this->localized($ultimate['name']),
            'draft_description' => $this->localized($ultimate['description']),
            'draft_small_image_url' => $this->optionalPrereqImage($ultimate['small_image'] ?? $ultimate['image'] ?? null),
            'draft_banner_image_url' => $this->optionalPrereqImage($ultimate['banner_image'] ?? $ultimate['image'] ?? null),
            'draft_difficulty' => ActivityType::DIFFICULTY_ULTIMATE,
            'draft_default_min_item_level' => $ultimate['default_min_item_level'] ?? null,
            'draft_bench_size' => $ultimate['bench_size'] ?? 8,
            'draft_fflogs_zone_id' => $ultimate['fflogs_zone_id'] ?? null,
            'draft_layout_schema' => $this->standardEightPlayerLayoutSchema(),
            'draft_slot_schema' => $this->standardRaidSlotSchema(),
            'draft_application_schema' => $this->standardRaidApplicationSchema(),
            'draft_progress_schema' => [
                'milestones' => $this->ultimateProgressMilestones($ultimate['fflogs_encounters'] ?? []),
            ],
            'draft_prog_points' => $this->ultimateProgPoints($ultimate['fflogs_encounters'] ?? []),
            'tags' => $ultimate['tags'] ?? [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $encounters
     * @return array<int, array<string, mixed>>
     */
    private function ultimateProgressMilestones(array $encounters): array
    {
        $milestones = [];
        $order = 1;

        foreach ($encounters as $encounter) {
            $encounterId = (int) ($encounter['encounter_id'] ?? 0);

            foreach ($encounter['phases'] ?? [] as $phase) {
                $milestones[] = $this->progressMilestone(
                    key: sprintf('%s-%s', $encounter['key'], $phase['key']),
                    label: $this->localizedSame((string) $phase['name']),
                    order: $order,
                    encounterId: $encounterId,
                    phaseId: (int) $phase['phase_id'],
                );

                $order++;
            }
        }

        return $milestones;
    }

    /**
     * @param  array<int, array<string, mixed>>  $encounters
     * @return array<int, array<string, mixed>>
     */
    private function ultimateProgPoints(array $encounters): array
    {
        return collect($this->ultimateProgressMilestones($encounters))
            ->map(fn (array $milestone): array => $this->progPoint(
                (string) $milestone['key'],
                $milestone['label'],
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function standardEightPlayerLayoutSchema(): array
    {
        return [
            'groups' => [
                $this->group('party', ['en' => 'Party', 'de' => 'Gruppe', 'fr' => 'Equipe', 'ja' => 'PT'], 8),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function standardRaidSlotSchema(): array
    {
        return [
            $this->schemaField(
                key: 'character_class',
                label: ['en' => 'Character Class', 'de' => 'Klasse', 'fr' => 'Classe', 'ja' => 'ジョブ'],
                type: 'single_select',
                source: 'character_classes',
            ),
            $this->schemaField(
                key: 'raid_position',
                label: ['en' => 'Raid Position', 'de' => 'Raid-Position', 'fr' => 'Position de raid', 'ja' => 'レイドポジション'],
                type: 'single_select',
                source: 'static_options',
                options: $this->raidPositionOptions(),
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function standardRaidApplicationSchema(): array
    {
        return [
            $this->schemaField(
                key: 'preferred_character_classes',
                label: ['en' => 'Preferred Character Classes', 'de' => 'Bevorzugte Klassen', 'fr' => 'Classes preferees', 'ja' => '希望ジョブ'],
                type: 'multi_select',
                source: 'character_classes',
            ),
            $this->schemaField(
                key: 'preferred_raid_positions',
                label: ['en' => 'Preferred Raid Positions', 'de' => 'Bevorzugte Raid-Positionen', 'fr' => 'Positions de raid preferees', 'ja' => '希望ポジション'],
                type: 'multi_select',
                source: 'static_options',
                options: $this->raidPositionOptions(),
                acceptsAny: true,
                anyLabel: ['en' => 'Put Me Anywhere Coach', 'de' => 'Setz mich ein, Coach', 'fr' => 'Placez-moi ou vous voulez', 'ja' => 'どこでも大丈夫です'],
            ),
            $this->schemaField(
                key: 'relevant_experience',
                label: ['en' => 'Relevant Experience', 'de' => 'Relevante Erfahrung', 'fr' => 'Experience pertinente', 'ja' => '関連経験'],
                type: 'textarea',
                source: null,
            ),
            $this->schemaField(
                key: 'fflogs_link',
                label: ['en' => 'FFLogs Link', 'de' => 'FFLogs-Link', 'fr' => 'Lien FFLogs', 'ja' => 'FFLogsリンク'],
                type: 'url',
                source: null,
            ),
            $this->schemaField(
                key: 'lodestone_link',
                label: ['en' => 'Lodestone Link', 'de' => 'Lodestone-Link', 'fr' => 'Lien Lodestone', 'ja' => 'Lodestoneリンク'],
                type: 'url',
                source: null,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $key, array|string $label, int $size, ?string $compositionHintKey = null): array
    {
        $compositionHintKey ??= match ($size) {
            4 => 'thdd',
            8 => 'tthhdddd',
            default => null,
        };

        return array_filter([
            'key' => $key,
            'label' => $this->localized($label),
            'size' => $size,
            'composition_hint_key' => $compositionHintKey,
            'composition_hints' => $compositionHintKey
                ? ActivityCompositionPresets::compositionHintsForKey($compositionHintKey)
                : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $options
     * @return array<string, mixed>
     */
    private function schemaField(
        string $key,
        array|string $label,
        string $type,
        ?string $source,
        bool $required = true,
        ?array $options = null,
        array|string|null $helpText = null,
        bool $acceptsAny = false,
        array|string|null $anyLabel = null,
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $this->localized($label),
            'type' => $type,
            'source' => $source,
            'required' => $required,
            'help_text' => $this->localized($helpText ?? ''),
            'options' => $options,
            'accepts_any' => $acceptsAny ? true : null,
            'any_label' => $acceptsAny ? $this->localized($anyLabel ?? 'Any') : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function raidPositionOptions(): array
    {
        return [
            $this->staticOption('mt', ['en' => 'Main Tank', 'de' => 'Main Tank', 'fr' => 'Tank principal', 'ja' => 'MT']),
            $this->staticOption('ot', ['en' => 'Off Tank', 'de' => 'Off Tank', 'fr' => 'Off tank', 'ja' => 'ST']),
            $this->staticOption('h1', ['en' => 'Healer 1', 'de' => 'Heiler 1', 'fr' => 'Soigneur 1', 'ja' => 'H1']),
            $this->staticOption('h2', ['en' => 'Healer 2', 'de' => 'Heiler 2', 'fr' => 'Soigneur 2', 'ja' => 'H2']),
            $this->staticOption('m1', ['en' => 'DPS 1 / Melee 1', 'de' => 'DPS 1 / Nahkampf 1', 'fr' => 'DPS 1 / Melee 1', 'ja' => 'DPS 1 / M1']),
            $this->staticOption('m2', ['en' => 'DPS 2 / Melee 2', 'de' => 'DPS 2 / Nahkampf 2', 'fr' => 'DPS 2 / Melee 2', 'ja' => 'DPS 2 / M2']),
            $this->staticOption('r1', ['en' => 'DPS 3 / Phys Ranged', 'de' => 'DPS 3 / Phys. Fernkampf', 'fr' => 'DPS 3 / Distance physique', 'ja' => 'DPS 3 / Phys Ranged']),
            $this->staticOption('r2', ['en' => 'DPS 4 / Magic Ranged', 'de' => 'DPS 4 / Mag. Fernkampf', 'fr' => 'DPS 4 / Distance magique', 'ja' => 'DPS 4 / Magic Ranged']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staticOption(string $value, array|string $label): array
    {
        return [
            'value' => $value,
            'label' => $this->localized($label),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progressMilestone(
        string $key,
        array|string $label,
        int $order,
        int $encounterId,
        ?int $phaseId = null,
    ): array {
        return [
            'key' => $key,
            'label' => $this->localized($label),
            'order' => $order,
            'fflogs_matcher' => [
                'type' => $phaseId === null ? 'encounter' : 'phase',
                'encounter_id' => $encounterId,
                'phase_id' => $phaseId,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function progPoint(string $key, array|string $label): array
    {
        return [
            'key' => $key,
            'label' => $this->localized($label),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function localized(array|string $value): array
    {
        if (is_array($value)) {
            return [
                'en' => $value['en'] ?? '',
                'de' => $value['de'] ?? '',
                'fr' => $value['fr'] ?? '',
                'ja' => $value['ja'] ?? '',
            ];
        }

        return [
            'en' => $value,
            'de' => '',
            'fr' => '',
            'ja' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function localizedSame(string $value): array
    {
        return [
            'en' => $value,
            'de' => $value,
            'fr' => $value,
            'ja' => $value,
        ];
    }

    private function prereqImage(string $filename): string
    {
        $path = public_path('prereqimages/'.$filename);

        if (! file_exists($path)) {
            throw new RuntimeException(sprintf('Expected prerequisite image [%s] to exist before seeding ultimate activity types.', $filename));
        }

        return '/prereqimages/'.$filename;
    }

    private function optionalPrereqImage(mixed $filename): ?string
    {
        if (! is_string($filename) || $filename === '') {
            return null;
        }

        return $this->prereqImage($filename);
    }

    /**
     * @param  array<int, mixed>  $tagNames
     */
    private function syncTags(ActivityType $activityType, array $tagNames): void
    {
        $tagIds = collect($tagNames)
            ->map(fn (mixed $tagName) => is_string($tagName) ? trim($tagName) : null)
            ->filter(fn (?string $tagName): bool => filled($tagName))
            ->unique()
            ->map(fn (string $tagName): int => ActivityTag::query()->firstOrCreate(['name' => $tagName])->id)
            ->all();

        $activityType->tags()->sync($tagIds);
    }
}
