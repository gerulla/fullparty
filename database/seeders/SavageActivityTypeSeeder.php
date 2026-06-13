<?php

namespace Database\Seeders;

use App\Models\ActivityTag;
use App\Models\ActivityType;
use App\Models\User;
use App\Support\ActivityCompositionPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SavageActivityTypeSeeder extends Seeder
{
    /**
     * Seed the application's savage raid activity types.
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

                $this->syncTags($activityType, $activityTypeData['tags'] ?? []);
            });
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activityTypes(): array
    {
        return $this->savageRaidActivityTypes();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function savageRaidActivityTypes(): array
    {
        return array_map(
            fn (array $fight): array => $this->savageRaidActivityType($fight),
            $this->savageRaidFightBlueprints(),
        );
    }

    /**
     * Add fight-specific savage records here as data becomes available.
     *
     * @return array<int, array<string, mixed>>
     */
    private function savageRaidFightBlueprints(): array
    {
        return [
            ...$this->arcadionSavageRaidFightBlueprints(),
            ...$this->pandemoniumSavageRaidFightBlueprints(),
            ...$this->edenSavageRaidFightBlueprints(),
            ...$this->omegaSavageRaidFightBlueprints(),
            ...$this->alexanderSavageRaidFightBlueprints(),
            ...$this->bahamutRaidFightBlueprints(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function arcadionSavageRaidFightBlueprints(): array
    {
        return [
            $this->savageFight(
                slug: 'aac-light-heavyweight-m1-savage',
                name: 'AAC Light-heavyweight M1 (Savage)',
                shortName: 'M1S',
                image: 'savage/arcadion/aac-light-heavyweight-m1-savage.png',
                minItemLevel: 700,
                description: 'A deadlier imagined rematch against Black Cat, inspired by Gabbro\'s exaggerated retelling of your original bout.',
                fflogsZoneId: 62,
                encounters: [
                    ['key' => 'm1s', 'name' => 'AAC Light-heavyweight M1', 'encounter_id' => 93],
                ],
            ),
            $this->savageFight(
                slug: 'aac-light-heavyweight-m2-savage',
                name: 'AAC Light-heavyweight M2 (Savage)',
                shortName: 'M2S',
                image: 'savage/arcadion/aac-light-heavyweight-m2-savage.png',
                minItemLevel: 705,
                description: 'A harsher imagined version of the fight against Honey B. Lovely, wondering what would happen if her vile side fully emerged.',
                fflogsZoneId: 62,
                encounters: [
                    ['key' => 'm2s', 'name' => 'AAC Light-heavyweight M2', 'encounter_id' => 94],
                ],
            ),
            $this->savageFight(
                slug: 'aac-light-heavyweight-m3-savage',
                name: 'AAC Light-heavyweight M3 (Savage)',
                shortName: 'M3S',
                image: 'savage/arcadion/aac-light-heavyweight-m3-savage.png',
                minItemLevel: 710,
                description: 'A more explosive imagined clash with Brute Bomber, where the uncrowned champion fights even dirtier to seize victory.',
                fflogsZoneId: 62,
                encounters: [
                    ['key' => 'm3s', 'name' => 'AAC Light-heavyweight M3', 'encounter_id' => 95],
                ],
            ),
            $this->savageFight(
                slug: 'aac-light-heavyweight-m4-savage',
                name: 'AAC Light-heavyweight M4 (Savage)',
                shortName: 'M4S',
                image: 'savage/arcadion/aac-light-heavyweight-m4-savage.png',
                minItemLevel: 710,
                description: 'An alternate imagined struggle against Wicked Thunder, exploring what might have happened if she had not fled.',
                fflogsZoneId: 62,
                encounters: [
                    ['key' => 'm4s', 'name' => 'AAC Light-heavyweight M4', 'encounter_id' => 96],
                ],
            ),
            $this->savageFight(
                slug: 'aac-cruiserweight-m1-savage',
                name: 'AAC Cruiserweight M1 (Savage)',
                shortName: 'M5S',
                image: 'savage/arcadion/aac-cruiserweight-m1-savage.png',
                minItemLevel: 730,
                description: 'A fever-pitch imagined rematch with Dancing Green, asking how the fight might have gone if the night reached greater intensity.',
                fflogsZoneId: 68,
                encounters: [
                    ['key' => 'm5s', 'name' => 'AAC Cruiserweight M1', 'encounter_id' => 97],
                ],
            ),
            $this->savageFight(
                slug: 'aac-cruiserweight-m2-savage',
                name: 'AAC Cruiserweight M2 (Savage)',
                shortName: 'M6S',
                image: 'savage/arcadion/aac-cruiserweight-m2-savage.png',
                minItemLevel: 735,
                description: 'A wilder imagined bout with Sugar Riot, where her creative power runs fully rampant.',
                fflogsZoneId: 68,
                encounters: [
                    ['key' => 'm6s', 'name' => 'AAC Cruiserweight M2', 'encounter_id' => 98],
                ],
            ),
            $this->savageFight(
                slug: 'aac-cruiserweight-m3-savage',
                name: 'AAC Cruiserweight M3 (Savage)',
                shortName: 'M7S',
                image: 'savage/arcadion/aac-cruiserweight-m3-savage.png',
                minItemLevel: 740,
                description: 'A more savage imagined battle against the Brute Abombinator, pushed beyond the devastation of the original fight.',
                fflogsZoneId: 68,
                encounters: [
                    ['key' => 'm7s', 'name' => 'AAC Cruiserweight M3', 'encounter_id' => 99],
                ],
            ),
            $this->savageFight(
                slug: 'aac-cruiserweight-m4-savage',
                name: 'AAC Cruiserweight M4 (Savage)',
                shortName: 'M8S',
                image: 'savage/arcadion/aac-cruiserweight-m4-savage.png',
                minItemLevel: 740,
                description: 'An extended imagined title match against the Howling Blade, wielding the full might of Fenrir.',
                fflogsZoneId: 68,
                encounters: [
                    ['key' => 'm8s', 'name' => 'AAC Cruiserweight M4', 'encounter_id' => 100],
                ],
            ),
            $this->savageFight(
                slug: 'aac-heavyweight-m1-savage',
                name: 'AAC Heavyweight M1 (Savage)',
                shortName: 'M9S',
                image: 'savage/arcadion/aac-heavyweight-m1-savage.png',
                minItemLevel: 760,
                description: 'A nightmarish imagined version of Vamp Fatale\'s spectacle, where her punishment becomes even more severe.',
                fflogsZoneId: 73,
                encounters: [
                    ['key' => 'm9s', 'name' => 'AAC Heavyweight M1', 'encounter_id' => 101],
                ],
            ),
            $this->savageFight(
                slug: 'aac-heavyweight-m2-savage',
                name: 'AAC Heavyweight M2 (Savage)',
                shortName: 'M10S',
                image: 'savage/arcadion/aac-heavyweight-m2-savage.png',
                minItemLevel: 765,
                description: 'A more extreme imagined tag-team fight against the Xtremes, where their swagger is backed by real skill.',
                fflogsZoneId: 73,
                encounters: [
                    ['key' => 'm10s', 'name' => 'AAC Heavyweight M2', 'encounter_id' => 102],
                ],
            ),
            $this->savageFight(
                slug: 'aac-heavyweight-m3-savage',
                name: 'AAC Heavyweight M3 (Savage)',
                shortName: 'M11S',
                image: 'savage/arcadion/aac-heavyweight-m3-savage.png',
                minItemLevel: 770,
                description: 'A decisive imagined rematch with the Tyrant, drawing even more power from the behemoth\'s soul.',
                fflogsZoneId: 73,
                encounters: [
                    ['key' => 'm11s', 'name' => 'AAC Heavyweight M3', 'encounter_id' => 103],
                ],
            ),
            $this->savageFight(
                slug: 'aac-heavyweight-m4-savage',
                name: 'AAC Heavyweight M4 (Savage)',
                shortName: 'M12S',
                image: 'savage/arcadion/aac-heavyweight-m4-savage.png',
                minItemLevel: 770,
                description: 'A dreadful imagined battle against the Lindwurm, envisioning its regeneration as truly limitless.',
                fflogsZoneId: 73,
                encounters: [
                    ['key' => 'lindwurm1', 'name' => 'Lindwurm I', 'encounter_id' => 104],
                    ['key' => 'lindwurm2', 'name' => 'Lindwurm II', 'encounter_id' => 105],
                ],
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pandemoniumSavageRaidFightBlueprints(): array
    {
        return $this->savageTierFightBlueprints(
            tierDirectory: 'pandemonium',
            fights: [
                [
                    'slug' => 'asphodelos-the-first-circle-savage',
                    'name' => 'Asphodelos: The First Circle (Savage)',
                    'short_name' => 'P1S',
                    'min_item_level' => 570,
                    'description' => 'A heightened retelling of the battle with Erichthonios at the Gates of Pandaemonium, shaped by Nemjiji\'s enthusiastic embellishments.',
                    'fflogs_zone_id' => 44,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p1s',
                            'name' => 'Erichthonios',
                            'encounter_id' => 78,
                        ],
                    ],
                ],
                [
                    'slug' => 'asphodelos-the-second-circle-savage',
                    'name' => 'Asphodelos: The Second Circle (Savage)',
                    'short_name' => 'P2S',
                    'min_item_level' => 575,
                    'description' => 'An exaggerated recounting of the fight against Hippokampos, with Nemjiji adding dramatic flair to the original encounter.',
                    'fflogs_zone_id' => 44,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p2s',
                            'name' => 'Hippokampos',
                            'encounter_id' => 79,
                        ],
                    ],
                ],
                [
                    'slug' => 'asphodelos-the-third-circle-savage',
                    'name' => 'Asphodelos: The Third Circle (Savage)',
                    'short_name' => 'P3S',
                    'min_item_level' => 580,
                    'description' => 'A fiery embellished version of the clash with the Phoinix, born from Nemjiji\'s frenzied account of the battle.',
                    'fflogs_zone_id' => 44,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p3s',
                            'name' => 'Phoinix',
                            'encounter_id' => 80,
                        ],
                    ],
                ],
                [
                    'slug' => 'asphodelos-the-fourth-circle-savage',
                    'name' => 'Asphodelos: The Fourth Circle (Savage)',
                    'short_name' => 'P4S',
                    'min_item_level' => 580,
                    'description' => 'The dramatic conclusion of Asphodelos, retelling the confrontation with Hesperos through Nemjiji\'s increasingly excitable lens.',
                    'fflogs_zone_id' => 44,
                    'fflogs_encounters' => [
                        [
                            'key' => 'hesperos',
                            'name' => 'Hesperos',
                            'encounter_id' => 81,
                        ],
                        [
                            'key' => 'hesperos2',
                            'name' => 'Hesperos II',
                            'encounter_id' => 82,
                        ],
                    ],
                ],
                [
                    'slug' => 'abyssos-the-fifth-circle-savage',
                    'name' => 'Abyssos: The Fifth Circle (Savage)',
                    'short_name' => 'P5S',
                    'min_item_level' => 600,
                    'description' => 'A more fantastical account of the battle with Proto-Carbuncle, colored by Nemjiji\'s fascination with mythic creations.',
                    'fflogs_zone_id' => 49,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p5s',
                            'name' => 'Proto-Carbuncle',
                            'encounter_id' => 83,
                        ],
                    ],
                ],
                [
                    'slug' => 'abyssos-the-sixth-circle-savage',
                    'name' => 'Abyssos: The Sixth Circle (Savage)',
                    'short_name' => 'P6S',
                    'min_item_level' => 605,
                    'description' => 'An animated retelling of the battle with Hegemone, made more vivid by Nemjiji\'s dramatic interpretation.',
                    'fflogs_zone_id' => 49,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p6s',
                            'name' => 'Hegemone',
                            'encounter_id' => 84,
                        ],
                    ],
                ],
                [
                    'slug' => 'abyssos-the-seventh-circle-savage',
                    'name' => 'Abyssos: The Seventh Circle (Savage)',
                    'short_name' => 'P7S',
                    'min_item_level' => 610,
                    'description' => 'A legendary retelling of Agdistis\'s fall, with Nemjiji blending scientific notes and embellishments into the account.',
                    'fflogs_zone_id' => 49,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p7s',
                            'name' => 'Agdistis',
                            'encounter_id' => 85,
                        ],
                    ],
                ],
                [
                    'slug' => 'abyssos-the-eighth-circle-savage',
                    'name' => 'Abyssos: The Eighth Circle (Savage)',
                    'short_name' => 'P8S',
                    'min_item_level' => 610,
                    'description' => 'The conclusion of Abyssos, reimagined through Nemjiji\'s theories after Pandæmonium drifts into the modern age.',
                    'fflogs_zone_id' => 49,
                    'fflogs_encounters' => [
                        [
                            'key' => 'hephaistos',
                            'name' => 'Hephaistos',
                            'encounter_id' => 86,
                        ],
                        [
                            'key' => 'hephaistos2',
                            'name' => 'Hephaistos II',
                            'encounter_id' => 87,
                        ],
                    ],
                ],
                [
                    'slug' => 'anabaseios-the-ninth-circle-savage',
                    'name' => 'Anabaseios: The Ninth Circle (Savage)',
                    'short_name' => 'P9S',
                    'min_item_level' => 630,
                    'description' => 'A theoretical retelling of the battle with Kokytos, sparked by renewed research into the Heart of Sabik.',
                    'fflogs_zone_id' => 54,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p9s',
                            'name' => 'Kokytos',
                            'encounter_id' => 88,
                        ],
                    ],
                ],
                [
                    'slug' => 'anabaseios-the-tenth-circle-savage',
                    'name' => 'Anabaseios: The Tenth Circle (Savage)',
                    'short_name' => 'P10S',
                    'min_item_level' => 635,
                    'description' => 'A dramatic account of the battle with Pandæmonium itself, exploring the strange idea of a living prison.',
                    'fflogs_zone_id' => 54,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p10s',
                            'name' => 'Pandaemonium',
                            'encounter_id' => 89,
                        ],
                    ],
                ],
                [
                    'slug' => 'anabaseios-the-eleventh-circle-savage',
                    'name' => 'Anabaseios: The Eleventh Circle (Savage)',
                    'short_name' => 'P11S',
                    'min_item_level' => 640,
                    'description' => 'A tragic and embellished retelling of the battle with Themis, framed as a tale of friendship, manipulation, and triumph.',
                    'fflogs_zone_id' => 54,
                    'fflogs_encounters' => [
                        [
                            'key' => 'p11s',
                            'name' => 'Themis',
                            'encounter_id' => 90,
                        ],
                    ],
                ],
                [
                    'slug' => 'anabaseios-the-twelfth-circle-savage',
                    'name' => 'Anabaseios: The Twelfth Circle (Savage)',
                    'short_name' => 'P12S',
                    'min_item_level' => 640,
                    'description' => 'The final Savage battle of Pandæmonium, reimagining the confrontation with Athena through Nemjiji\'s boundless imagination.',
                    'fflogs_zone_id' => 54,
                    'fflogs_encounters' => [
                        [
                            'key' => 'athena',
                            'name' => 'Athena',
                            'encounter_id' => 91,
                        ],
                        [
                            'key' => 'pallas-athena',
                            'name' => 'Pallas Athena',
                            'encounter_id' => 92,
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function edenSavageRaidFightBlueprints(): array
    {
        return $this->savageTierFightBlueprints(
            tierDirectory: 'eden',
            fights: [
                [
                    'slug' => 'edens-gate-resurrection-savage',
                    'name' => 'Eden\'s Gate: Resurrection (Savage)',
                    'short_name' => 'E1S',
                    'min_item_level' => 440,
                    'description' => 'A savage memory from Eden\'s core, twisting your encounter with Eden Prime into a far more ferocious illusion.',
                    'fflogs_zone_id' => 29,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e1s',
                            'name' => 'Eden Prime',
                            'encounter_id' => 65,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-gate-descent-savage',
                    'name' => 'Eden\'s Gate: Descent (Savage)',
                    'short_name' => 'E2S',
                    'min_item_level' => 445,
                    'description' => 'A more dangerous vision of the battle against Voidwalker, drawn from the distorted memories within Eden\'s core.',
                    'fflogs_zone_id' => 29,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e2s',
                            'name' => 'Voidwalker',
                            'encounter_id' => 66,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-gate-inundation-savage',
                    'name' => 'Eden\'s Gate: Inundation (Savage)',
                    'short_name' => 'E3S',
                    'min_item_level' => 450,
                    'description' => 'A savage illusion of Leviathan, reshaping your memory of the Empty into a deadlier watery trial.',
                    'fflogs_zone_id' => 29,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e3s',
                            'name' => 'Leviathan',
                            'encounter_id' => 67,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-gate-sepulture-savage',
                    'name' => 'Eden\'s Gate: Sepulture (Savage)',
                    'short_name' => 'E4S',
                    'min_item_level' => 450,
                    'description' => 'A savage vision of Titan and Titan Maximum, turning your restored memory of earth into a brutal final Gate encounter.',
                    'fflogs_zone_id' => 29,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e4s',
                            'name' => 'Titan',
                            'encounter_id' => 68,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-verse-fulmination-savage',
                    'name' => 'Eden\'s Verse: Fulmination (Savage)',
                    'short_name' => 'E5S',
                    'min_item_level' => 470,
                    'description' => 'A savage crystal vision that revives Ramuh as a more brutal and unrelenting foe.',
                    'fflogs_zone_id' => 33,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e5s',
                            'name' => 'Ramuh',
                            'encounter_id' => 69,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-verse-furor-savage',
                    'name' => 'Eden\'s Verse: Furor (Savage)',
                    'short_name' => 'E6S',
                    'min_item_level' => 475,
                    'description' => 'A savage retelling of the elemental clash against Garuda and Ifrit, culminating in Raktapaksa.',
                    'fflogs_zone_id' => 33,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e6s',
                            'name' => 'Ifrit and Garuda',
                            'encounter_id' => 70,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-verse-iconoclasm-savage',
                    'name' => 'Eden\'s Verse: Iconoclasm (Savage)',
                    'short_name' => 'E7S',
                    'min_item_level' => 480,
                    'description' => 'A savage manifestation of the Idol of Darkness, rebuilt from Eden\'s distorted crystal memories.',
                    'fflogs_zone_id' => 33,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e7s',
                            'name' => 'The Idol of Darkness',
                            'encounter_id' => 71,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-verse-refulgence-savage',
                    'name' => 'Eden\'s Verse: Refulgence (Savage)',
                    'short_name' => 'E8S',
                    'min_item_level' => 480,
                    'description' => 'A savage vision of Shiva, transforming the memory of ice and light into the climactic battle of Eden\'s Verse.',
                    'fflogs_zone_id' => 33,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e8s',
                            'name' => 'Shiva',
                            'encounter_id' => 72,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-promise-umbra-savage',
                    'name' => 'Eden\'s Promise: Umbra (Savage)',
                    'short_name' => 'E9S',
                    'min_item_level' => 500,
                    'description' => 'A perilous distorted memory from the Empty, confronting you with a savage Cloud of Darkness.',
                    'fflogs_zone_id' => 38,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e9s',
                            'name' => 'Cloud of Darkness',
                            'encounter_id' => 73,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-promise-litany-savage',
                    'name' => 'Eden\'s Promise: Litany (Savage)',
                    'short_name' => 'E10S',
                    'min_item_level' => 505,
                    'description' => 'A savage distorted memory of the Shadowkeeper, revisiting the struggle in a far more dangerous form.',
                    'fflogs_zone_id' => 38,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e10s',
                            'name' => 'Shadowkeeper',
                            'encounter_id' => 74,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-promise-anamorphosis-savage',
                    'name' => 'Eden\'s Promise: Anamorphosis (Savage)',
                    'short_name' => 'E11S',
                    'min_item_level' => 510,
                    'description' => 'A savage memory of Fatebreaker, reimagining the battle with elemental power and greater peril.',
                    'fflogs_zone_id' => 38,
                    'fflogs_encounters' => [
                        [
                            'key' => 'e11s',
                            'name' => 'Fatebreaker',
                            'encounter_id' => 75,
                        ],
                    ],
                ],
                [
                    'slug' => 'edens-promise-eternity-savage',
                    'name' => 'Eden\'s Promise: Eternity (Savage)',
                    'short_name' => 'E12S',
                    'min_item_level' => 510,
                    'description' => 'The final savage memory of Eden\'s Promise, beginning with Eden\'s Promise and culminating in the Oracle of Darkness.',
                    'fflogs_zone_id' => 38,
                    'fflogs_encounters' => [
                        [
                            'key' => 'edens-promise',
                            'name' => 'Eden\'s Promise',
                            'encounter_id' => 76,
                        ],
                        [
                            'key' => 'oracle-of-darkness',
                            'name' => 'Oracle of Darkness',
                            'encounter_id' => 77,
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function omegaSavageRaidFightBlueprints(): array
    {
        return $this->savageTierFightBlueprints(
            tierDirectory: 'omega',
            fights: [
                [
                    'slug' => 'deltascape-v10-savage',
                    'name' => 'Deltascape V1.0 (Savage)',
                    'short_name' => 'O1S',
                    'min_item_level' => 310,
                    'description' => 'A Savage Initiative simulation of the first Deltascape trial, turning the battle against Alte Roite into a heightened challenge.',
                    'fflogs_zone_id' => 17,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o1s',
                            'name' => 'Alte Roite',
                            'encounter_id' => 42,
                        ],
                    ],
                ],
                [
                    'slug' => 'deltascape-v20-savage',
                    'name' => 'Deltascape V2.0 (Savage)',
                    'short_name' => 'O2S',
                    'min_item_level' => 315,
                    'description' => 'A Savage Initiative version of the second Deltascape trial, reimagining Catastrophe as a more punishing opponent.',
                    'fflogs_zone_id' => 17,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o2s',
                            'name' => 'Catastrophe',
                            'encounter_id' => 43,
                        ],
                    ],
                ],
                [
                    'slug' => 'deltascape-v30-savage',
                    'name' => 'Deltascape V3.0 (Savage)',
                    'short_name' => 'O3S',
                    'min_item_level' => 320,
                    'description' => 'A Savage Initiative version of the third Deltascape trial, amplifying the battle against Halicarnassus.',
                    'fflogs_zone_id' => 17,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o3s',
                            'name' => 'Halicarnassus',
                            'encounter_id' => 44,
                        ],
                    ],
                ],
                [
                    'slug' => 'deltascape-v40-savage',
                    'name' => 'Deltascape V4.0 (Savage)',
                    'short_name' => 'O4S',
                    'min_item_level' => 320,
                    'description' => 'The Savage Initiative conclusion of Deltascape, beginning with Exdeath and escalating into Neo Exdeath.',
                    'fflogs_zone_id' => 17,
                    'fflogs_encounters' => [
                        [
                            'key' => 'exdeath',
                            'name' => 'Exdeath',
                            'encounter_id' => 45,
                        ],
                        [
                            'key' => 'neo-exdeath',
                            'name' => 'Neo Exdeath',
                            'encounter_id' => 46,
                        ],
                    ],
                ],
                [
                    'slug' => 'sigmascape-v10-savage',
                    'name' => 'Sigmascape V1.0 (Savage)',
                    'short_name' => 'O5S',
                    'min_item_level' => 340,
                    'description' => 'A Savage Initiative version of the first Sigmascape trial, sending you into a deadlier encounter with Phantom Train.',
                    'fflogs_zone_id' => 21,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o5s',
                            'name' => 'Phantom Train',
                            'encounter_id' => 47,
                        ],
                    ],
                ],
                [
                    'slug' => 'sigmascape-v20-savage',
                    'name' => 'Sigmascape V2.0 (Savage)',
                    'short_name' => 'O6S',
                    'min_item_level' => 345,
                    'description' => 'A Savage Initiative version of the second Sigmascape trial, intensifying the battle against Demon Chadarnook.',
                    'fflogs_zone_id' => 21,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o6s',
                            'name' => 'Demon Chadarnook',
                            'encounter_id' => 48,
                        ],
                    ],
                ],
                [
                    'slug' => 'sigmascape-v30-savage',
                    'name' => 'Sigmascape V3.0 (Savage)',
                    'short_name' => 'O7S',
                    'min_item_level' => 350,
                    'description' => 'A Savage Initiative version of the third Sigmascape trial, reworking Guardian into a more complex test.',
                    'fflogs_zone_id' => 21,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o7s',
                            'name' => 'Guardian',
                            'encounter_id' => 49,
                        ],
                    ],
                ],
                [
                    'slug' => 'sigmascape-v40-savage',
                    'name' => 'Sigmascape V4.0 (Savage)',
                    'short_name' => 'O8S',
                    'min_item_level' => 350,
                    'description' => 'The Savage Initiative finale of Sigmascape, beginning with Kefka and culminating in God Kefka.',
                    'fflogs_zone_id' => 21,
                    'fflogs_encounters' => [
                        [
                            'key' => 'kefka',
                            'name' => 'Kefka',
                            'encounter_id' => 50,
                        ],
                        [
                            'key' => 'god-kefka',
                            'name' => 'God Kefka',
                            'encounter_id' => 51,
                        ],
                    ],
                ],
                [
                    'slug' => 'alphascape-v10-savage',
                    'name' => 'Alphascape V1.0 (Savage)',
                    'short_name' => 'O9S',
                    'min_item_level' => 370,
                    'description' => 'A Savage Initiative version of the first Alphascape trial, turning Chaos into a sharper and more dangerous challenge.',
                    'fflogs_zone_id' => 25,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o9s',
                            'name' => 'Chaos',
                            'encounter_id' => 60,
                        ],
                    ],
                ],
                [
                    'slug' => 'alphascape-v20-savage',
                    'name' => 'Alphascape V2.0 (Savage)',
                    'short_name' => 'O10S',
                    'min_item_level' => 375,
                    'description' => 'A Savage Initiative version of the second Alphascape trial, reimagining Midgardsormr as a more severe opponent.',
                    'fflogs_zone_id' => 25,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o10s',
                            'name' => 'Midgardsormr',
                            'encounter_id' => 61,
                        ],
                    ],
                ],
                [
                    'slug' => 'alphascape-v30-savage',
                    'name' => 'Alphascape V3.0 (Savage)',
                    'short_name' => 'O11S',
                    'min_item_level' => 380,
                    'description' => 'A Savage Initiative version of the third Alphascape trial, forcing a more demanding battle against Omega.',
                    'fflogs_zone_id' => 25,
                    'fflogs_encounters' => [
                        [
                            'key' => 'o11s',
                            'name' => 'Omega',
                            'encounter_id' => 62,
                        ],
                    ],
                ],
                [
                    'slug' => 'alphascape-v40-savage',
                    'name' => 'Alphascape V4.0 (Savage)',
                    'short_name' => 'O12S',
                    'min_item_level' => 380,
                    'description' => 'The Savage Initiative finale of Omega, starting with Omega-M and Omega-F before reaching The Final Omega.',
                    'fflogs_zone_id' => 25,
                    'fflogs_encounters' => [
                        [
                            'key' => 'omega-m-and-omega-f',
                            'name' => 'Omega-M and Omega-F',
                            'encounter_id' => 63,
                        ],
                        [
                            'key' => 'the-final-omega',
                            'name' => 'The Final Omega',
                            'encounter_id' => 64,
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function alexanderSavageRaidFightBlueprints(): array
    {
        return $this->savageTierFightBlueprints(
            tierDirectory: 'alexander',
            fights: [
                [
                    'slug' => 'alexander-the-fist-of-the-father-savage',
                    'name' => 'Alexander - The Fist of the Father (Savage)',
                    'short_name' => 'A1S',
                    'min_item_level' => 190,
                    'description' => 'The first Gordias Savage encounter, pitting you against Faust and Oppressor in a tougher version of the Father’s opening sector.',
                    'fflogs_zone_id' => 7,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a1s',
                            'name' => 'Oppressor',
                            'encounter_id' => 18,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-cuff-of-the-father-savage',
                    'name' => 'Alexander - The Cuff of the Father (Savage)',
                    'short_name' => 'A2S',
                    'min_item_level' => 195,
                    'description' => 'A Savage version of the Father’s second sector, focused on waves of Illuminati forces and battlefield control.',
                    'fflogs_zone_id' => 7,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a2s',
                            'name' => 'The Cuff of the Father',
                            'encounter_id' => 19,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-arm-of-the-father-savage',
                    'name' => 'Alexander - The Arm of the Father (Savage)',
                    'short_name' => 'A3S',
                    'min_item_level' => 200,
                    'description' => 'A punishing Savage battle against Living Liquid, one of Gordias’s most infamous mechanical and damage checks.',
                    'fflogs_zone_id' => 7,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a3s',
                            'name' => 'Living Liquid',
                            'encounter_id' => 20,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-burden-of-the-father-savage',
                    'name' => 'Alexander - The Burden of the Father (Savage)',
                    'short_name' => 'A4S',
                    'min_item_level' => 205,
                    'description' => 'The final Gordias Savage encounter, challenging you with The Manipulator and the climax of the Father tier.',
                    'fflogs_zone_id' => 7,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a4s',
                            'name' => 'The Manipulator',
                            'encounter_id' => 21,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-fist-of-the-son-savage',
                    'name' => 'Alexander - The Fist of the Son (Savage)',
                    'short_name' => 'A5S',
                    'min_item_level' => 215,
                    'description' => 'The first Midas Savage encounter, beginning with Hummelfaust before the main battle against Ratfinx Twinkledinks.',
                    'fflogs_zone_id' => 10,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a5s',
                            'name' => 'Ratfinx Twinkledinks',
                            'encounter_id' => 26,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-cuff-of-the-son-savage',
                    'name' => 'Alexander - The Cuff of the Son (Savage)',
                    'short_name' => 'A6S',
                    'min_item_level' => 220,
                    'description' => 'A multi-boss Midas Savage encounter featuring Blaster, Brawler, Swindler, and Vortexer.',
                    'fflogs_zone_id' => 10,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a6s',
                            'name' => 'Vortexer',
                            'encounter_id' => 27,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-arm-of-the-son-savage',
                    'name' => 'Alexander - The Arm of the Son (Savage)',
                    'short_name' => 'A7S',
                    'min_item_level' => 225,
                    'description' => 'A Savage battle against Quickthinx Allthoughts, built around traps, jails, and arena hazards.',
                    'fflogs_zone_id' => 10,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a7s',
                            'name' => 'Quickthinx Allthoughts',
                            'encounter_id' => 28,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-burden-of-the-son-savage',
                    'name' => 'Alexander - The Burden of the Son (Savage)',
                    'short_name' => 'A8S',
                    'min_item_level' => 225,
                    'description' => 'The Midas Savage finale, culminating in the iconic battle against Brute Justice.',
                    'fflogs_zone_id' => 10,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a8s',
                            'name' => 'Brute Justice',
                            'encounter_id' => 29,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-eyes-of-the-creator-savage',
                    'name' => 'Alexander - The Eyes of the Creator (Savage)',
                    'short_name' => 'A9S',
                    'min_item_level' => 245,
                    'description' => 'The opening Creator Savage encounter, beginning with Faust Z before the fight against Refurbisher 0.',
                    'fflogs_zone_id' => 13,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a9s',
                            'name' => 'Refurbisher 0',
                            'encounter_id' => 34,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-breath-of-the-creator-savage',
                    'name' => 'Alexander - The Breath of the Creator (Savage)',
                    'short_name' => 'A10S',
                    'min_item_level' => 250,
                    'description' => 'A Creator Savage fight against Lamebrix Strikebocks, centered on platform manipulation and goblin machinery.',
                    'fflogs_zone_id' => 13,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a10s',
                            'name' => 'Lamebrix Strikebocks',
                            'encounter_id' => 35,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-heart-of-the-creator-savage',
                    'name' => 'Alexander - The Heart of the Creator (Savage)',
                    'short_name' => 'A11S',
                    'min_item_level' => 255,
                    'description' => 'A Savage encounter against Cruise Chaser, testing the party with high-speed transformations and precise movement.',
                    'fflogs_zone_id' => 13,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a11s',
                            'name' => 'Cruise Chaser',
                            'encounter_id' => 36,
                        ],
                    ],
                ],
                [
                    'slug' => 'alexander-the-soul-of-the-creator-savage',
                    'name' => 'Alexander - The Soul of the Creator (Savage)',
                    'short_name' => 'A12S',
                    'min_item_level' => 255,
                    'description' => 'The final Alexander Savage encounter, bringing the raid series to its climax against Alexander Prime.',
                    'fflogs_zone_id' => 13,
                    'fflogs_encounters' => [
                        [
                            'key' => 'a12s',
                            'name' => 'Alexander Prime',
                            'encounter_id' => 37,
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bahamutRaidFightBlueprints(): array
    {
        return $this->savageTierFightBlueprints(
            tierDirectory: 'bahamut',
            fights: [
                [
                    'slug' => 'the-binding-coil-of-bahamut-turn-1',
                    'name' => 'The Binding Coil of Bahamut - Turn 1',
                    'short_name' => 'T1',
                    'min_item_level' => 70,
                    'description' => 'The opening turn of the Coils, taking you beneath Castrum Occidens and into Dalamud\'s remnants before a battle with Caduceus.',
                    'fflogs_zone_id' => 1,
                    'fflogs_encounters' => [
                        [
                            'key' => 't1',
                            'name' => 'Caduceus',
                            'encounter_id' => 1,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-binding-coil-of-bahamut-turn-2',
                    'name' => 'The Binding Coil of Bahamut - Turn 2',
                    'short_name' => 'T2',
                    'min_item_level' => 73,
                    'description' => 'A descent through Dalamud\'s internal defenses, ending in a battle against ADS and its node-based systems.',
                    'fflogs_zone_id' => 1,
                    'fflogs_encounters' => [
                        [
                            'key' => 't2',
                            'name' => 'ADS',
                            'encounter_id' => 2,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-binding-coil-of-bahamut-turn-3',
                    'name' => 'The Binding Coil of Bahamut - Turn 3',
                    'short_name' => 'T3',
                    'min_item_level' => 70,
                    'description' => 'A traversal turn through the Ragnarok-class starship, focused on exploration and enemies rather than a traditional boss.',
                    'fflogs_zone_id' => 1,
                    'fflogs_encounters' => [
                        [
                            'key' => 't3',
                            'name' => 'The Ragnarok',
                            'encounter_id' => 3,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-binding-coil-of-bahamut-turn-4',
                    'name' => 'The Binding Coil of Bahamut - Turn 4',
                    'short_name' => 'T4',
                    'min_item_level' => 77,
                    'description' => 'A gauntlet-style encounter against waves of Allagan constructs known collectively as the Clockwork Brigade.',
                    'fflogs_zone_id' => 1,
                    'fflogs_encounters' => [
                        [
                            'key' => 't4',
                            'name' => 'Clockwork Brigade',
                            'encounter_id' => 4,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-binding-coil-of-bahamut-turn-5',
                    'name' => 'The Binding Coil of Bahamut - Turn 5',
                    'short_name' => 'T5',
                    'min_item_level' => 82,
                    'description' => 'The final Binding Coil encounter, facing Twintania in one of A Realm Reborn\'s most infamous raid battles.',
                    'fflogs_zone_id' => 1,
                    'fflogs_encounters' => [
                        [
                            'key' => 't5',
                            'name' => 'Twintania',
                            'encounter_id' => 5,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-second-coil-of-bahamut-turn-1',
                    'name' => 'The Second Coil of Bahamut - Turn 1',
                    'short_name' => 'T6',
                    'min_item_level' => 90,
                    'description' => 'The first Second Coil turn, sending the party into Dalamud\'s Shadow for a battle against Rafflesia.',
                    'fflogs_zone_id' => 3,
                    'fflogs_encounters' => [
                        [
                            'key' => 't6',
                            'name' => 'Rafflesia',
                            'encounter_id' => 6,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-second-coil-of-bahamut-turn-2',
                    'name' => 'The Second Coil of Bahamut - Turn 2',
                    'short_name' => 'T7',
                    'min_item_level' => 95,
                    'description' => 'A journey through the Outer Coil, culminating in a petrification-heavy battle against Melusine.',
                    'fflogs_zone_id' => 3,
                    'fflogs_encounters' => [
                        [
                            'key' => 't7',
                            'name' => 'Melusine',
                            'encounter_id' => 7,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-second-coil-of-bahamut-turn-3',
                    'name' => 'The Second Coil of Bahamut - Turn 3',
                    'short_name' => 'T8',
                    'min_item_level' => 100,
                    'description' => 'A Central Decks encounter against the Avatar, testing the party with Allagan systems and arena control.',
                    'fflogs_zone_id' => 3,
                    'fflogs_encounters' => [
                        [
                            'key' => 't8',
                            'name' => 'The Avatar',
                            'encounter_id' => 8,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-second-coil-of-bahamut-turn-4',
                    'name' => 'The Second Coil of Bahamut - Turn 4',
                    'short_name' => 'T9',
                    'min_item_level' => 105,
                    'description' => 'The Second Coil finale, confronting Nael deus Darnus in the Holocharts beneath the earth.',
                    'fflogs_zone_id' => 3,
                    'fflogs_encounters' => [
                        [
                            'key' => 't9',
                            'name' => 'Nael deus Darnus',
                            'encounter_id' => 9,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-final-coil-of-bahamut-turn-1',
                    'name' => 'The Final Coil of Bahamut - Turn 1',
                    'short_name' => 'T10',
                    'min_item_level' => 110,
                    'description' => 'The opening Final Coil battle, beginning the last push toward Bahamut with a fight against Imdugud.',
                    'fflogs_zone_id' => 5,
                    'fflogs_encounters' => [
                        [
                            'key' => 't10',
                            'name' => 'Imdugud',
                            'encounter_id' => 14,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-final-coil-of-bahamut-turn-2',
                    'name' => 'The Final Coil of Bahamut - Turn 2',
                    'short_name' => 'T11',
                    'min_item_level' => 115,
                    'description' => 'A Final Coil encounter against Kaliya, combining Allagan technology, adds, and heavy positional mechanics.',
                    'fflogs_zone_id' => 5,
                    'fflogs_encounters' => [
                        [
                            'key' => 't11',
                            'name' => 'Kaliya',
                            'encounter_id' => 15,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-final-coil-of-bahamut-turn-3',
                    'name' => 'The Final Coil of Bahamut - Turn 3',
                    'short_name' => 'T12',
                    'min_item_level' => 120,
                    'description' => 'A climactic battle against Phoenix, tied closely to the fate of Louisoix and the truth of the Calamity.',
                    'fflogs_zone_id' => 5,
                    'fflogs_encounters' => [
                        [
                            'key' => 't12',
                            'name' => 'Phoenix',
                            'encounter_id' => 16,
                        ],
                    ],
                ],
                [
                    'slug' => 'the-final-coil-of-bahamut-turn-4',
                    'name' => 'The Final Coil of Bahamut - Turn 4',
                    'short_name' => 'T13',
                    'min_item_level' => 123,
                    'description' => 'The final Coil encounter, bringing the Seventh Umbral Era\'s lingering threat to a close in battle against Bahamut Prime.',
                    'fflogs_zone_id' => 5,
                    'fflogs_encounters' => [
                        [
                            'key' => 't13',
                            'name' => 'Bahamut Prime',
                            'encounter_id' => 17,
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $fights
     * @return array<int, array<string, mixed>>
     */
    private function savageTierFightBlueprints(string $tierDirectory, array $fights): array
    {
        return array_map(
            fn (array $fight): array => $this->savageFight(
                slug: (string) $fight['slug'],
                name: (string) $fight['name'],
                shortName: (string) $fight['short_name'],
                image: sprintf('savage/%s/%s.png', $tierDirectory, $fight['slug']),
                minItemLevel: (int) $fight['min_item_level'],
                description: (string) $fight['description'],
                fflogsZoneId: (int) $fight['fflogs_zone_id'],
                encounters: $fight['fflogs_encounters'],
            ),
            $fights,
        );
    }

    /**
     * @param  array<int, array{key: string, name: string, encounter_id: int}>  $encounters
     * @return array<string, mixed>
     */
    private function savageFight(
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
            'description' => $this->localized([
                'en' => "{$shortName}: {$description}",
                'de' => "{$shortName}: {$description}",
                'fr' => "{$shortName}: {$description}",
                'ja' => "{$shortName}: {$description}",
            ]),
            'small_image' => $image,
            'banner_image' => $image,
            'default_min_item_level' => $minItemLevel,
            'fflogs_zone_id' => $fflogsZoneId,
            'fflogs_encounters' => $encounters,
            'tags' => [$shortName],
        ];
    }

    /**
     * @param  array<string, mixed>  $fight
     * @return array<string, mixed>
     */
    private function savageRaidActivityType(array $fight): array
    {
        return [
            'slug' => (string) $fight['slug'],
            'draft_name' => $this->localized($fight['name']),
            'draft_description' => $this->localized($fight['description']),
            'draft_small_image_url' => $this->optionalPrereqImage($fight['small_image'] ?? $fight['image'] ?? null),
            'draft_banner_image_url' => $this->optionalPrereqImage($fight['banner_image'] ?? $fight['image'] ?? null),
            'draft_difficulty' => ActivityType::DIFFICULTY_SAVAGE,
            'draft_default_min_item_level' => $fight['default_min_item_level'] ?? null,
            'draft_bench_size' => $fight['bench_size'] ?? 8,
            'draft_fflogs_zone_id' => $fight['fflogs_zone_id'] ?? null,
            'draft_layout_schema' => $this->savageRaidLayoutSchema(),
            'draft_slot_schema' => $this->savageRaidSlotSchema(),
            'draft_application_schema' => $this->savageRaidApplicationSchema(),
            'draft_progress_schema' => [
                'milestones' => $fight['progress_milestones'] ?? $this->savageRaidProgressMilestones($fight['fflogs_encounters'] ?? []),
            ],
            'draft_prog_points' => $fight['prog_points'] ?? $this->savageRaidProgPoints($fight['fflogs_encounters'] ?? []),
            'tags' => $fight['tags'] ?? [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $encounters
     * @return array<int, array<string, mixed>>
     */
    private function savageRaidProgressMilestones(array $encounters): array
    {
        return collect($encounters)
            ->values()
            ->map(fn (array $encounter, int $index): array => $this->progressMilestone(
                key: (string) $encounter['key'],
                label: $this->localizedSame((string) $encounter['name']),
                order: $index + 1,
                encounterId: (int) $encounter['encounter_id'],
            ))
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $encounters
     * @return array<int, array<string, mixed>>
     */
    private function savageRaidProgPoints(array $encounters): array
    {
        return collect($encounters)
            ->map(fn (array $encounter): array => $this->progPoint(
                (string) $encounter['key'],
                $this->localizedSame((string) $encounter['name']),
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function savageRaidLayoutSchema(): array
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
    private function savageRaidSlotSchema(): array
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
                source: 'raid_positions',
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function savageRaidApplicationSchema(): array
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
                source: 'raid_positions',
                acceptsAny: true,
                anyLabel: ['en' => 'Put Me Anywhere Coach', 'de' => 'Setz mich ein, wo du willst, Coach', 'fr' => 'Mets-moi où tu veux, coach', 'ja' => 'どこでもいいです'],
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

    private function prereqImage(string $filename): string
    {
        $path = public_path('prereqimages/'.$filename);

        if (! file_exists($path)) {
            throw new RuntimeException(sprintf('Expected prerequisite image [%s] to exist before seeding activity types.', $filename));
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
