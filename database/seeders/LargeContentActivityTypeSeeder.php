<?php

namespace Database\Seeders;

use App\Models\ActivityType;
use App\Models\PhantomJob;
use App\Models\User;
use App\Support\ActivityCompositionPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LargeContentActivityTypeSeeder extends Seeder
{
    /**
     * Seed the application's large content activity types.
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
                    'draft_difficulty' => $activityTypeData['draft_difficulty'] ?? ActivityType::DIFFICULTY_NORMAL,
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
                    'difficulty' => $activityTypeData['draft_difficulty'] ?? ActivityType::DIFFICULTY_NORMAL,
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
                    'en' => 'Forked Tower of Blood',
                    'de' => 'Forked Tower of Blood',
                    'fr' => 'Tour Bifurquee de Sang',
                    'ja' => 'Forked Tower of Blood',
                ]),
                'draft_description' => $this->localized([
                    'en' => 'Large-scale Forked Tower activity with 6 parties, class, raid position, and phantom job slot assignments, plus multilingual application preferences.',
                    'de' => 'Gross angelegte Forked-Tower-Aktivitaet mit 6 Gruppen, Klassen-, Raid-Positions- und Phantomjob-Zuweisungen pro Slot sowie mehrsprachigen Bewerbungsangaben.',
                    'fr' => 'Activite Forked Tower a grande echelle avec 6 groupes, affectation de classe, position de raid et job fantome par slot, et preferences de candidature multilingues.',
                    'ja' => '6PT構成、各枠にクラス、レイドポジション、ファントムジョブを設定でき、多言語の申請項目を持つ大規模なForked Tower向けアクティビティです。',
                ]),
                'draft_small_image_url' => $this->prereqImage('forked.jpg'),
                'draft_banner_image_url' => $this->prereqImage('forked.jpg'),
                'draft_difficulty' => ActivityType::DIFFICULTY_EXPLORATION,
                'draft_bench_size' => 8,
                'draft_fflogs_zone_id' => 69,
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
                        key: 'raid_position',
                        label: ['en' => 'Raid Position', 'de' => 'Raid-Position', 'fr' => 'Position de raid', 'ja' => 'レイドポジション'],
                        type: 'single_select',
                        source: 'raid_positions',
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
                        key: 'preferred_raid_positions',
                        label: ['en' => 'Preferred Raid Positions', 'de' => 'Bevorzugte Raid-Positionen', 'fr' => 'Positions de raid preferees', 'ja' => '希望ポジション'],
                        type: 'multi_select',
                        source: 'raid_positions',
                        acceptsAny: true,
                        anyLabel: ['en' => 'Put Me Anywhere Coach', 'de' => 'Setz mich ein, wo du willst, Coach', 'fr' => 'Mets-moi où tu veux, coach', 'ja' => 'どこでもいいです'],
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
                        label: ['en' => 'Want to Party Lead', 'de' => 'Moechte die Gruppe leiten', 'fr' => 'Souhaite lead le groupe', 'ja' => 'PTリーダー希望'],
                        type: 'boolean',
                        source: null,
                    ),
                    $this->schemaField(
                        key: 'can_be_on_standby',
                        label: ['en' => 'Can be on standby?', 'de' => 'Kann auf Abruf bereitstehen?', 'fr' => 'Peut etre en standby ?', 'ja' => '待機参加は可能ですか？'],
                        type: 'boolean',
                        source: null,
                        helpText: [
                            'en' => 'Use this if you are willing to be placed on the bench or be on-call in case anyone scheduled is missing or drops out.',
                            'de' => 'Nutze dies, wenn du bereit bist, auf die Bank gesetzt zu werden oder auf Abruf bereitzustehen, falls eingeplante Spieler fehlen oder ausfallen.',
                            'fr' => 'Utilisez ceci si vous acceptez d etre place sur le banc ou d etre joignable au cas ou une personne prevue serait absente ou se desisterait.',
                            'ja' => '予定メンバーが欠席したり離脱した場合に、ベンチ待機や呼び出し対応が可能であれば選択してください。',
                        ],
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
                ],
                'draft_roster_summary_presets' => [
                    $this->rosterSummaryPreset(
                        key: 'minimal-composition',
                        label: [
                            'en' => 'Minimal Composition',
                            'de' => 'Minimale Komposition',
                            'fr' => 'Composition minimale',
                            'ja' => '最低構成',
                        ],
                        description: [
                            'en' => 'This is the bare minimum set of jobs needed to clear.',
                            'de' => 'Dies ist das absolute Minimum an Jobs, das fuer einen Clear noetig ist.',
                            'fr' => 'Il s agit du minimum strict de jobs necessaires pour reussir le clear.',
                            'ja' => 'クリアに必要な最低限のジョブ構成です。',
                        ],
                        requirements: [
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Bard'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Ranger'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Thief'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Geomancer'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Time Mage'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Bard'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Ranger'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Thief'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Geomancer'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Time Mage'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Oracle'), 'at_least', 1, 'all_slots'),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Berserker'), 'at_least', 1, 'all_slots'),
                        ],
                    ),
                    $this->rosterSummaryPreset(
                        key: 'recommended-composition',
                        label: [
                            'en' => 'Recommended Composition',
                            'de' => 'Empfohlene Komposition',
                            'fr' => 'Composition recommandee',
                            'ja' => '推奨構成',
                        ],
                        description: [
                            'en' => 'A safer, more rounded composition with the most useful support coverage on each side.',
                            'de' => 'Eine sicherere und rundere Komposition mit der wichtigsten Support-Abdeckung auf beiden Seiten.',
                            'fr' => 'Une composition plus sure et plus complete avec la couverture de soutien la plus utile de chaque cote.',
                            'ja' => '各サイドに有用な支援ジョブを十分に揃えた、より安定した推奨構成です。',
                        ],
                        requirements: [
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Bard'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Ranger'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Thief'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Geomancer'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Time Mage'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Cannoneer'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Mystic Knight'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Bard'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Ranger'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Thief'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Geomancer'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Time Mage'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Cannoneer'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Mystic Knight'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Berserker'), 'at_least', 1, 'all_slots'),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Oracle'), 'at_least', 1, 'all_slots'),
                        ],
                    ),
                    $this->rosterSummaryPreset(
                        key: 'risky-minmax-composition',
                        label: [
                            'en' => 'Risky Min-Maxing Composition',
                            'de' => 'Riskante Min-Max-Komposition',
                            'fr' => 'Composition min-max risquee',
                            'ja' => 'ハイリスク最適化構成',
                        ],
                        description: [
                            'en' => 'A greedier setup that trims safety tools in favor of more aggressive damage output.',
                            'de' => 'Eine gierigere Aufstellung, die Sicherheitswerkzeuge zugunsten von mehr Schaden reduziert.',
                            'fr' => 'Une composition plus agressive qui reduit les outils de securite au profit de degats plus eleves.',
                            'ja' => '安全枠を削って火力を優先した、より欲張りな最適化構成です。',
                        ],
                        requirements: [
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Bard'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Ranger'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Geomancer'), 'at_least', 2, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Time Mage'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Cannoneer'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Mystic Knight'), 'at_least', 1, 'slot_group_set', ['party-a', 'party-b', 'party-c']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Bard'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Ranger'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Geomancer'), 'at_least', 2, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Time Mage'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Cannoneer'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Mystic Knight'), 'at_least', 1, 'slot_group_set', ['party-d', 'party-e', 'party-f']),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Berserker'), 'at_least', 6, 'all_slots'),
                            $this->rosterSummaryRequirement('phantom_jobs', $this->phantomJobId('Phantom Oracle'), 'at_least', 3, 'all_slots'),
                        ],
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
                'draft_prog_points' => [
                    $this->progPoint('demon-tablet', ['en' => 'Demon Tablet', 'de' => 'Demon Tablet', 'fr' => 'Demon Tablet', 'ja' => 'Demon Tablet']),
                    $this->progPoint('dead-stars', ['en' => 'Dead Stars', 'de' => 'Dead Stars', 'fr' => 'Dead Stars', 'ja' => 'Dead Stars']),
                    $this->progPoint('bridges', ['en' => 'Bridges', 'de' => 'Bridges', 'fr' => 'Bridges', 'ja' => 'Bridges']),
                    $this->progPoint('marble-dragon', ['en' => 'Marble Dragon', 'de' => 'Marble Dragon', 'fr' => 'Marble Dragon', 'ja' => 'Marble Dragon']),
                    $this->progPoint('puzzle', ['en' => 'Puzzle', 'de' => 'Puzzle', 'fr' => 'Puzzle', 'ja' => 'Puzzle']),
                    $this->progPoint('magitaur', ['en' => 'Magitaur', 'de' => 'Magitaur', 'fr' => 'Magitaur', 'ja' => 'Magitaur']),
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
                'draft_small_image_url' => $this->prereqImage('chaotic_small.png'),
                'draft_banner_image_url' => $this->prereqImage('chaotic.webp'),
                'draft_difficulty' => ActivityType::DIFFICULTY_CHAOTIC,
                'draft_bench_size' => 8,
                'draft_fflogs_zone_id' => 66,
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
                        source: 'raid_positions',
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
                        source: 'raid_positions',
                        acceptsAny: true,
                        anyLabel: ['en' => 'Put Me Anywhere Coach', 'de' => 'Setz mich ein, wo du willst, Coach', 'fr' => 'Mets-moi où tu veux, coach', 'ja' => 'どこでもいいです'],
                    ),
                    $this->schemaField(
                        key: 'can_be_on_standby',
                        label: ['en' => 'Can be on standby?', 'de' => 'Kann auf Abruf bereitstehen?', 'fr' => 'Peut etre en standby ?', 'ja' => '待機参加は可能ですか？'],
                        type: 'boolean',
                        source: null,
                        helpText: [
                            'en' => 'Use this if you are willing to be placed on the bench or be on-call in case anyone scheduled is missing or drops out.',
                            'de' => 'Nutze dies, wenn du bereit bist, auf die Bank gesetzt zu werden oder auf Abruf bereitzustehen, falls eingeplante Spieler fehlen oder ausfallen.',
                            'fr' => 'Utilisez ceci si vous acceptez d etre place sur le banc ou d etre joignable au cas ou une personne prevue serait absente ou se desisterait.',
                            'ja' => '予定メンバーが欠席したり離脱した場合に、ベンチ待機や呼び出し対応が可能であれば選択してください。',
                        ],
                    ),
                ],
                'draft_progress_schema' => [
                    'milestones' => [],
                ],
                'draft_prog_points' => [],
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
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array<string, mixed>
     */
    private function rosterSummaryPreset(
        string $key,
        array|string $label,
        array|string $description,
        array $requirements,
    ): array {
        return [
            'key' => $key,
            'label' => $this->localized($label),
            'description' => $this->localized($description),
            'requirements' => $requirements,
        ];
    }

    /**
     * @param  array<int, string>  $scopeGroupKeys
     * @return array<string, mixed>
     */
    private function rosterSummaryRequirement(
        string $source,
        int $sourceId,
        string $comparison,
        int $targetCount,
        string $scopeType,
        array $scopeGroupKeys = [],
    ): array {
        return [
            'source' => $source,
            'source_id' => $sourceId,
            'comparison' => $comparison,
            'target_count' => $targetCount,
            'scope_type' => $scopeType,
            'scope_group_keys' => $scopeGroupKeys,
        ];
    }

    private function prereqImage(string $filename): string
    {
        $path = public_path('prereqimages/'.$filename);

        if (! file_exists($path)) {
            throw new RuntimeException(sprintf('Expected prerequisite image [%s] to exist before seeding activity types.', $filename));
        }

        return '/prereqimages/'.$filename;
    }

    private function phantomJobId(string $name): int
    {
        $phantomJobId = PhantomJob::query()
            ->where('name', $name)
            ->value('id');

        if (! is_numeric($phantomJobId)) {
            throw new RuntimeException(sprintf('Expected phantom job [%s] to exist before seeding activity types.', $name));
        }

        return (int) $phantomJobId;
    }
}
