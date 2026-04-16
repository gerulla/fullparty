<?php

namespace Database\Seeders;

use App\Models\ActivityType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityTypeSeeder extends Seeder
{
    /**
     * Seed the application's activity types.
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
                    'draft_layout_schema' => $activityTypeData['draft_layout_schema'],
                    'draft_slot_schema' => $activityTypeData['draft_slot_schema'],
                    'draft_application_schema' => $activityTypeData['draft_application_schema'],
                    'draft_progress_schema' => $activityTypeData['draft_progress_schema'],
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
                    'layout_schema' => $activityTypeData['draft_layout_schema'],
                    'slot_schema' => $activityTypeData['draft_slot_schema'],
                    'application_schema' => $activityTypeData['draft_application_schema'],
                    'progress_schema' => $activityTypeData['draft_progress_schema'],
                    'published_by_user_id' => $publisherId,
                    'published_at' => now(),
                ]);

                $activityType->forceFill([
                    'current_published_version_id' => $version->id,
                ])->save();
            });
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activityTypes(): array
    {
        return [
            [
                'slug' => 'forked-tower',
                'draft_name' => $this->localized([
                    'en' => 'Forked Tower',
                    'de' => 'Forked Tower',
                    'fr' => 'Tour Bifurquee',
                    'ja' => 'Forked Tower',
                ]),
                'draft_description' => $this->localized([
                    'en' => 'Large-scale Forked Tower activity with 6 parties, class + phantom job slot assignments, and multilingual application preferences.',
                    'de' => 'Gross angelegte Forked-Tower-Aktivitaet mit 6 Gruppen, Klassen- und Phantomjob-Zuweisungen pro Slot sowie mehrsprachigen Bewerbungsangaben.',
                    'fr' => 'Activite Forked Tower a grande echelle avec 6 groupes, affectation de classe et de job fantome par slot, et preferences de candidature multilingues.',
                    'ja' => '6PT構成、各枠にクラスとファントムジョブを設定でき、多言語の申請項目を持つ大規模なForked Tower向けアクティビティです。',
                ]),
                'draft_layout_schema' => [
                    'groups' => [
                        $this->group('party-a', ['en' => 'Party A', 'de' => 'Gruppe A', 'fr' => 'Equipe A', 'ja' => 'PT A'], 8),
                        $this->group('party-b', ['en' => 'Party B', 'de' => 'Gruppe B', 'fr' => 'Equipe B', 'ja' => 'PT B'], 8),
                        $this->group('party-c', ['en' => 'Party C', 'de' => 'Gruppe C', 'fr' => 'Equipe C', 'ja' => 'PT C'], 8),
                        $this->group('party-d', ['en' => 'Party D', 'de' => 'Gruppe D', 'fr' => 'Equipe D', 'ja' => 'PT D'], 8),
                        $this->group('party-e', ['en' => 'Party E', 'de' => 'Gruppe E', 'fr' => 'Equipe E', 'ja' => 'PT E'], 8),
                        $this->group('party-f', ['en' => 'Party F', 'de' => 'Gruppe F', 'fr' => 'Equipe F', 'ja' => 'PT F'], 8),
                    ],
                ],
                'draft_slot_schema' => [
                    $this->schemaField(
                        key: 'character_class',
                        label: ['en' => 'Character Class', 'de' => 'Klasse', 'fr' => 'Classe', 'ja' => 'ジョブ'],
                        type: 'single_select',
                        source: 'character_classes',
                    ),
                    $this->schemaField(
                        key: 'phantom_job',
                        label: ['en' => 'Phantom Job', 'de' => 'Phantomjob', 'fr' => 'Job fantome', 'ja' => 'ファントムジョブ'],
                        type: 'single_select',
                        source: 'phantom_jobs',
                    ),
                ],
                'draft_application_schema' => [
                    $this->schemaField(
                        key: 'preferred_character_classes',
                        label: ['en' => 'Preferred Character Classes', 'de' => 'Bevorzugte Klassen', 'fr' => 'Classes preferees', 'ja' => '希望ジョブ'],
                        type: 'multi_select',
                        source: 'character_classes',
                    ),
                    $this->schemaField(
                        key: 'preferred_phantom_jobs',
                        label: ['en' => 'Preferred Phantom Jobs', 'de' => 'Bevorzugte Phantomjobs', 'fr' => 'Jobs fantomes preferes', 'ja' => '希望ファントムジョブ'],
                        type: 'multi_select',
                        source: 'phantom_jobs',
                    ),
                    $this->schemaField(
                        key: 'can_solo_heal',
                        label: ['en' => 'Can Solo Heal', 'de' => 'Kann solo heilen', 'fr' => 'Peut soigner seul', 'ja' => 'ソロヒール可能'],
                        type: 'boolean',
                        source: null,
                    ),
                    $this->schemaField(
                        key: 'wants_to_party_lead',
                        label: ['en' => 'Wants to Party Lead', 'de' => 'Moechte die Gruppe leiten', 'fr' => 'Souhaite lead le groupe', 'ja' => 'PTリーダー希望'],
                        type: 'boolean',
                        source: null,
                    ),
                    $this->schemaField(
                        key: 'preferred_languages',
                        label: ['en' => 'Preferred Languages', 'de' => 'Bevorzugte Sprachen', 'fr' => 'Langues preferees', 'ja' => '希望言語'],
                        type: 'multi_select',
                        source: 'static_options',
                        options: [
                            $this->staticOption('en', ['en' => 'English', 'de' => 'Englisch', 'fr' => 'Anglais', 'ja' => '英語']),
                            $this->staticOption('fr', ['en' => 'French', 'de' => 'Franzoesisch', 'fr' => 'Francais', 'ja' => 'フランス語']),
                            $this->staticOption('de', ['en' => 'German', 'de' => 'Deutsch', 'fr' => 'Allemand', 'ja' => 'ドイツ語']),
                            $this->staticOption('ja', ['en' => 'Japanese', 'de' => 'Japanisch', 'fr' => 'Japonais', 'ja' => '日本語']),
                        ],
                    ),
                    $this->schemaField(
                        key: 'notes',
                        label: ['en' => 'Notes', 'de' => 'Notizen', 'fr' => 'Notes', 'ja' => 'メモ'],
                        type: 'textarea',
                        source: null,
                        required: false,
                    ),
                ],
                'draft_progress_schema' => [
                    'milestones' => [
                        $this->progressMilestone(
                            key: 'demon-tablet',
                            label: ['en' => 'Demon Tablet', 'de' => 'Demon Tablet', 'fr' => 'Demon Tablet', 'ja' => 'Demon Tablet'],
                            order: 1,
                            encounterId: 2062,
                        ),
                        $this->progressMilestone(
                            key: 'dead-stars',
                            label: ['en' => 'Dead Stars', 'de' => 'Dead Stars', 'fr' => 'Dead Stars', 'ja' => 'Dead Stars'],
                            order: 2,
                            encounterId: 2063,
                        ),
                        $this->progressMilestone(
                            key: 'marble-dragon',
                            label: ['en' => 'Marble Dragon', 'de' => 'Marble Dragon', 'fr' => 'Marble Dragon', 'ja' => 'Marble Dragon'],
                            order: 3,
                            encounterId: 2065,
                        ),
                        $this->progressMilestone(
                            key: 'magitaur',
                            label: ['en' => 'Magitaur', 'de' => 'Magitaur', 'fr' => 'Magitaur', 'ja' => 'Magitaur'],
                            order: 4,
                            encounterId: 2066,
                        ),
                    ],
                ],
            ],
            [
                'slug' => 'cloud-of-darkness-chaotic',
                'draft_name' => $this->localized([
                    'en' => 'Cloud of Darkness (Chaotic)',
                    'de' => 'Cloud of Darkness (Chaotic)',
                    'fr' => 'Nuage des Tenebres (Chaotique)',
                    'ja' => 'Cloud of Darkness (Chaotic)',
                ]),
                'draft_description' => $this->localized([
                    'en' => '24-player Chaotic activity with party-based slot assignments, character classes, and raid positions.',
                    'de' => '24-Spieler-Chaotic-Aktivitaet mit gruppenbasierten Slot-Zuweisungen, Klassen und Raid-Positionen.',
                    'fr' => 'Activite Chaotique a 24 joueurs avec affectation par groupe, classes et positions de raid.',
                    'ja' => '24人用のChaotic向けアクティビティ。PT単位の編成、ジョブ、レイドポジション設定に対応します。',
                ]),
                'draft_layout_schema' => [
                    'groups' => [
                        $this->group('party-a', ['en' => 'Party A', 'de' => 'Gruppe A', 'fr' => 'Equipe A', 'ja' => 'PT A'], 8),
                        $this->group('party-b', ['en' => 'Party B', 'de' => 'Gruppe B', 'fr' => 'Equipe B', 'ja' => 'PT B'], 8),
                        $this->group('party-c', ['en' => 'Party C', 'de' => 'Gruppe C', 'fr' => 'Equipe C', 'ja' => 'PT C'], 8),
                    ],
                ],
                'draft_slot_schema' => [
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
                ],
                'draft_application_schema' => [
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
                    ),
                    $this->schemaField(
                        key: 'notes',
                        label: ['en' => 'Notes', 'de' => 'Notizen', 'fr' => 'Notes', 'ja' => 'メモ'],
                        type: 'textarea',
                        source: null,
                        required: false,
                    ),
                ],
                'draft_progress_schema' => [
                    'milestones' => [],
                ],
            ],
            [
                'slug' => 'savage-raids',
                'draft_name' => $this->localized([
                    'en' => 'Savage Raids',
                    'de' => 'Savage-Raids',
                    'fr' => 'Raids Sadique',
                    'ja' => '零式レイド',
                ]),
                'draft_description' => $this->localized([
                    'en' => 'Standard 8-player savage raid activity with role-position planning and experience links.',
                    'de' => 'Standard-Aktivitaet fuer 8-Spieler-Savage-Raids mit Rollenpositionen und Erfahrungslinks.',
                    'fr' => 'Activite standard de raid sadique a 8 joueurs avec planification des positions et liens d\'experience.',
                    'ja' => '8人向け零式レイド用アクティビティ。ロール位置や経験リンクの提出に対応します。',
                ]),
                'draft_layout_schema' => [
                    'groups' => [
                        $this->group('party', ['en' => 'Party', 'de' => 'Gruppe', 'fr' => 'Equipe', 'ja' => 'PT'], 8),
                    ],
                ],
                'draft_slot_schema' => [
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
                ],
                'draft_application_schema' => [
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
                ],
                'draft_progress_schema' => [
                    'milestones' => [],
                ],
            ],
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
     * @return array<string, mixed>
     */
    private function group(string $key, array|string $label, int $size): array
    {
        return [
            'key' => $key,
            'label' => $this->localized($label),
            'size' => $size,
        ];
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
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $this->localized($label),
            'type' => $type,
            'source' => $source,
            'required' => $required,
            'help_text' => $this->localized(''),
            'options' => $options,
        ], static fn ($value) => $value !== null);
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
     * @return array<int, array<string, mixed>>
     */
    private function raidPositionOptions(): array
    {
        return [
            $this->staticOption('mt', ['en' => 'Main Tank', 'de' => 'Main Tank', 'fr' => 'Tank principal', 'ja' => 'MT']),
            $this->staticOption('ot', ['en' => 'Off Tank', 'de' => 'Off Tank', 'fr' => 'Off tank', 'ja' => 'ST']),
            $this->staticOption('h1', ['en' => 'Healer 1', 'de' => 'Heiler 1', 'fr' => 'Soigneur 1', 'ja' => 'H1']),
            $this->staticOption('h2', ['en' => 'Healer 2', 'de' => 'Heiler 2', 'fr' => 'Soigneur 2', 'ja' => 'H2']),
            $this->staticOption('m1', ['en' => 'Melee 1', 'de' => 'Nahkampf 1', 'fr' => 'Melee 1', 'ja' => 'M1']),
            $this->staticOption('m2', ['en' => 'Melee 2', 'de' => 'Nahkampf 2', 'fr' => 'Melee 2', 'ja' => 'M2']),
            $this->staticOption('r1', ['en' => 'Ranged 1', 'de' => 'Fernkampf 1', 'fr' => 'Distance 1', 'ja' => 'R1']),
            $this->staticOption('r2', ['en' => 'Ranged 2', 'de' => 'Fernkampf 2', 'fr' => 'Distance 2', 'ja' => 'R2']),
        ];
    }
}
