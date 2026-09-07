<?php

namespace Database\Seeders;

use App\Models\ActivityTag;
use App\Models\ActivityType;
use App\Models\User;
use App\Support\ActivityCompositionPresets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExtremeActivityTypeSeeder extends Seeder
{
    /**
     * Seed the application's Extreme and Unreal trial activity types.
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
                    'draft_difficulty' => $activityTypeData['draft_difficulty'],
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
                    'difficulty' => $activityTypeData['draft_difficulty'],
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
        return [
            ...$this->extremeTrialActivityTypes(),
            ...$this->unrealTrialActivityTypes(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extremeTrialActivityTypes(): array
    {
        return collect($this->extremeTrialData())
            ->flatMap(fn (array $trials, string $expansion): array => array_map(
                fn (array $trial): array => $this->trialActivityType($trial, ActivityType::DIFFICULTY_EXTREME, sprintf('extreme/%s', Str::slug($expansion))),
                $trials,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unrealTrialActivityTypes(): array
    {
        return array_map(
            fn (array $trial): array => $this->trialActivityType($trial, ActivityType::DIFFICULTY_UNREAL, 'unreal'),
            $this->unrealTrialData(),
        );
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function extremeTrialData(): array
    {
        $data = json_decode(<<<'JSON'
{
  "ExtremeTrials": {
    "Heavensward": [
      {
        "Name": "The Limitless Blue (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Limitless_Blue_%28Extreme%29.png",
        "tags": "Bismarck EX, EX1",
        "min_ilvl": 165,
        "description": "The Extreme battle against Bismarck in the skies above the Sea of Clouds.",
        "slug": "the-limitless-blue-extreme",
        "fflogs_zone_id": 4,
        "fflogs_encounters": [
          {
            "key": "bismarck-ex",
            "name": "Bismarck",
            "encounter_id": 1027
          }
        ]
      },
      {
        "Name": "Thok ast Thok (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Thok_ast_Thok_%28Extreme%29.png",
        "tags": "Ravana EX, EX2",
        "min_ilvl": 175,
        "description": "The Extreme battle against Ravana, the Lord of the Hive.",
        "slug": "thok-ast-thok-extreme",
        "fflogs_zone_id": 4,
        "fflogs_encounters": [
          {
            "key": "ravana-ex",
            "name": "Ravana",
            "encounter_id": 1028
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Thordan's Reign",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Thordan%27s_Reign.png",
        "tags": "Thordan EX, EX3",
        "min_ilvl": 190,
        "description": "The minstrel's heightened retelling of the battle against King Thordan and the Knights Twelve.",
        "slug": "the-minstrels-ballad-thordans-reign",
        "fflogs_zone_id": 4,
        "fflogs_encounters": [
          {
            "key": "thordan-ex",
            "name": "Thordan",
            "encounter_id": 1029
          }
        ]
      },
      {
        "Name": "Containment Bay S1T7 (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Containment_Bay_S1T7_%28Extreme%29.png",
        "tags": "Sephirot EX, EX4",
        "min_ilvl": 205,
        "description": "The Extreme Warring Triad battle against Sephirot, the Fiend.",
        "slug": "containment-bay-s1t7-extreme",
        "fflogs_zone_id": 4,
        "fflogs_encounters": [
          {
            "key": "sephirot-ex",
            "name": "Sephirot",
            "encounter_id": 1030
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Nidhogg's Rage",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Nidhogg%27s_Rage.png",
        "tags": "Nidhogg EX, EX5",
        "min_ilvl": 220,
        "description": "The minstrel's retelling of the final battle against Nidhogg.",
        "slug": "the-minstrels-ballad-nidhoggs-rage",
        "fflogs_zone_id": 4,
        "fflogs_encounters": [
          {
            "key": "nidhogg-ex",
            "name": "Nidhogg",
            "encounter_id": 1031
          }
        ]
      },
      {
        "Name": "Containment Bay P1T6 (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Containment_Bay_P1T6_%28Extreme%29.png",
        "tags": "Sophia EX, EX6",
        "min_ilvl": 235,
        "description": "The Extreme Warring Triad battle against Sophia, the Goddess.",
        "slug": "containment-bay-p1t6-extreme",
        "fflogs_zone_id": 4,
        "fflogs_encounters": [
          {
            "key": "sophia-ex",
            "name": "Sophia",
            "encounter_id": 1032
          }
        ]
      },
      {
        "Name": "Containment Bay Z1T9 (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Containment_Bay_Z1T9_%28Extreme%29.png",
        "tags": "Zurvan EX, EX7",
        "min_ilvl": 250,
        "description": "The Extreme Warring Triad battle against Zurvan, the Demon.",
        "slug": "containment-bay-z1t9-extreme",
        "fflogs_zone_id": 4,
        "fflogs_encounters": [
          {
            "key": "zurvan-ex",
            "name": "Zurvan",
            "encounter_id": 1033
          }
        ]
      }
    ],
    "Stormblood": [
      {
        "Name": "The Pool of Tribute (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Pool_of_Tribute_%28Extreme%29.png",
        "tags": "Susano EX, EX1",
        "min_ilvl": 300,
        "description": "The Extreme battle against Susano, Lord of the Revel.",
        "slug": "the-pool-of-tribute-extreme",
        "fflogs_zone_id": 15,
        "fflogs_encounters": [
          {
            "key": "susano-ex",
            "name": "Susano",
            "encounter_id": 1036
          }
        ]
      },
      {
        "Name": "Emanation (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Emanation_%28Extreme%29.png",
        "tags": "Lakshmi EX, EX2",
        "min_ilvl": 300,
        "description": "The Extreme battle against Lakshmi, Lady of Bliss.",
        "slug": "emanation-extreme",
        "fflogs_zone_id": 15,
        "fflogs_encounters": [
          {
            "key": "lakshmi-ex",
            "name": "Lakshmi",
            "encounter_id": 1037
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Shinryu's Domain",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Shinryu%27s_Domain.png",
        "tags": "Shinryu EX, EX3",
        "min_ilvl": 320,
        "description": "The minstrel's heightened version of the climactic battle against Shinryu.",
        "slug": "the-minstrels-ballad-shinryus-domain",
        "fflogs_zone_id": 15,
        "fflogs_encounters": [
          {
            "key": "shinryu-ex",
            "name": "Shinryu",
            "encounter_id": 1038
          }
        ]
      },
      {
        "Name": "The Jade Stoa (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Jade_Stoa_%28Extreme%29.png",
        "tags": "Byakko EX, EX4",
        "min_ilvl": 340,
        "description": "The Extreme Four Lords battle against Byakko.",
        "slug": "the-jade-stoa-extreme",
        "fflogs_zone_id": 15,
        "fflogs_encounters": [
          {
            "key": "byakko-ex",
            "name": "Byakko",
            "encounter_id": 1039
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Tsukuyomi's Pain",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Tsukuyomi%27s_Pain.png",
        "tags": "Tsukuyomi EX, EX5",
        "min_ilvl": 350,
        "description": "The minstrel's retelling of the tragic battle against Tsukuyomi.",
        "slug": "the-minstrels-ballad-tsukuyomis-pain",
        "fflogs_zone_id": 15,
        "fflogs_encounters": [
          {
            "key": "tsukuyomi-ex",
            "name": "Tsukuyomi",
            "encounter_id": 1040
          }
        ]
      },
      {
        "Name": "The Great Hunt (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Great_Hunt_%28Extreme%29.png",
        "tags": "Rathalos EX, EX6",
        "min_ilvl": 350,
        "description": "A four-player Extreme hunt against Rathalos from Monster Hunter.",
        "slug": "the-great-hunt-extreme",
        "fflogs_zone_id": null,
        "fflogs_encounters": [
          {
            "key": "rathalos-ex",
            "name": "Rathalos",
            "encounter_id": null
          }
        ]
      },
      {
        "Name": "Hells' Kier (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Hells%27_Kier_%28Extreme%29.png",
        "tags": "Suzaku EX, EX7",
        "min_ilvl": 370,
        "description": "The Extreme Four Lords battle against Suzaku.",
        "slug": "hells-kier-extreme",
        "fflogs_zone_id": 15,
        "fflogs_encounters": [
          {
            "key": "suzaku-ex",
            "name": "Suzaku",
            "encounter_id": 1042
          }
        ]
      },
      {
        "Name": "The Wreath of Snakes (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Wreath_of_Snakes_%28Extreme%29.png",
        "tags": "Seiryu EX, EX8",
        "min_ilvl": 380,
        "description": "The Extreme Four Lords battle against Seiryu.",
        "slug": "the-wreath-of-snakes-extreme",
        "fflogs_zone_id": 15,
        "fflogs_encounters": [
          {
            "key": "seiryu-ex",
            "name": "Seiryu",
            "encounter_id": 1043
          }
        ]
      }
    ],
    "Shadowbringers": [
      {
        "Name": "The Dancing Plague (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Dancing_Plague_%28Extreme%29.png",
        "tags": "Titania EX, EX1",
        "min_ilvl": 430,
        "description": "The Extreme battle against Titania, king of the fae.",
        "slug": "the-dancing-plague-extreme",
        "fflogs_zone_id": 28,
        "fflogs_encounters": [
          {
            "key": "titania-ex",
            "name": "Titania",
            "encounter_id": 1045
          }
        ]
      },
      {
        "Name": "The Crown of the Immaculate (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Crown_of_the_Immaculate_%28Extreme%29.png",
        "tags": "Innocence EX, EX2",
        "min_ilvl": 430,
        "description": "The Extreme battle against Innocence, the immaculate Lightwarden.",
        "slug": "the-crown-of-the-immaculate-extreme",
        "fflogs_zone_id": 28,
        "fflogs_encounters": [
          {
            "key": "innocence-ex",
            "name": "Innocence",
            "encounter_id": 1044
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Hades's Elegy",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Hades%27s_Elegy.png",
        "tags": "Hades EX, EX3",
        "min_ilvl": 450,
        "description": "The minstrel's dramatic retelling of the battle against Hades.",
        "slug": "the-minstrels-ballad-hadess-elegy",
        "fflogs_zone_id": 28,
        "fflogs_encounters": [
          {
            "key": "hades-ex",
            "name": "Hades",
            "encounter_id": 1046
          }
        ]
      },
      {
        "Name": "Cinder Drift (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Cinder_Drift_%28Extreme%29.png",
        "tags": "Ruby Weapon EX, EX4",
        "min_ilvl": 470,
        "description": "The Extreme Sorrow of Werlyt battle against the Ruby Weapon.",
        "slug": "cinder-drift-extreme",
        "fflogs_zone_id": 34,
        "fflogs_encounters": [
          {
            "key": "ruby-weapon-1",
            "name": "The Ruby Weapon I",
            "encounter_id": 1047
          },
          {
            "key": "ruby-weapon-2",
            "name": "The Ruby Weapon II",
            "encounter_id": 1048
          }
        ]
      },
      {
        "Name": "Memoria Misera (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Memoria_Misera_%28Extreme%29.png",
        "tags": "Varis EX, EX5",
        "min_ilvl": 470,
        "description": "An Extreme memory of battle against Varis yae Galvus.",
        "slug": "memoria-misera-extreme",
        "fflogs_zone_id": 34,
        "fflogs_encounters": [
          {
            "key": "varis-ex",
            "name": "Varis Yae Galvus",
            "encounter_id": 1049
          }
        ]
      },
      {
        "Name": "The Seat of Sacrifice (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Seat_of_Sacrifice_%28Extreme%29.png",
        "tags": "WoL EX, EX6",
        "min_ilvl": 480,
        "description": "The Extreme battle against the Warrior of Light.",
        "slug": "the-seat-of-sacrifice-extreme",
        "fflogs_zone_id": 34,
        "fflogs_encounters": [
          {
            "key": "warrior-of-light-ex",
            "name": "Warrior of Light",
            "encounter_id": 1050
          }
        ]
      },
      {
        "Name": "Castrum Marinum (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Castrum_Marinum_%28Extreme%29.png",
        "tags": "Emerald Weapon EX, EX7",
        "min_ilvl": 500,
        "description": "The Extreme Sorrow of Werlyt battle against the Emerald Weapon.",
        "slug": "castrum-marinum-extreme",
        "fflogs_zone_id": 37,
        "fflogs_encounters": [
          {
            "key": "emerald-weapon-1",
            "name": "The Emerald Weapon I",
            "encounter_id": 1051
          },
          {
            "key": "emerald-weapon-2",
            "name": "The Emerald Weapon II",
            "encounter_id": 1052
          }
        ]
      },
      {
        "Name": "The Cloud Deck (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Cloud_Deck_%28Extreme%29.png",
        "tags": "Diamond Weapon EX, EX8",
        "min_ilvl": 510,
        "description": "The Extreme Sorrow of Werlyt finale against the Diamond Weapon.",
        "slug": "the-cloud-deck-extreme",
        "fflogs_zone_id": 37,
        "fflogs_encounters": [
          {
            "key": "diamond-weapon-ex",
            "name": "The Diamond Weapon",
            "encounter_id": 1053
          }
        ]
      }
    ],
    "Endwalker": [
      {
        "Name": "The Minstrel's Ballad: Zodiark's Fall",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Zodiark%27s_Fall.png",
        "tags": "Zodiark EX, EX1",
        "min_ilvl": 560,
        "description": "The minstrel's retelling of the battle against Zodiark.",
        "slug": "the-minstrels-ballad-zodiarks-fall",
        "fflogs_zone_id": 42,
        "fflogs_encounters": [
          {
            "key": "zodiark-ex",
            "name": "Zodiark",
            "encounter_id": 1058
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Hydaelyn's Call",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Hydaelyn%27s_Call.png",
        "tags": "Hydaelyn EX, EX2",
        "min_ilvl": 560,
        "description": "The minstrel's retelling of the trial against Hydaelyn.",
        "slug": "the-minstrels-ballad-hydaelyns-call",
        "fflogs_zone_id": 42,
        "fflogs_encounters": [
          {
            "key": "hydaelyn-ex",
            "name": "Hydaelyn",
            "encounter_id": 1059
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Endsinger's Aria",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Endsinger%27s_Aria.png",
        "tags": "Endsinger EX, EX3",
        "min_ilvl": 580,
        "description": "The minstrel's heightened version of the final confrontation with the Endsinger.",
        "slug": "the-minstrels-ballad-endsingers-aria",
        "fflogs_zone_id": 42,
        "fflogs_encounters": [
          {
            "key": "endsinger-ex",
            "name": "Endsinger",
            "encounter_id": 1060
          }
        ]
      },
      {
        "Name": "Storm's Crown (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Storm%27s_Crown_%28Extreme%29.png",
        "tags": "Barbariccia EX, EX4",
        "min_ilvl": 600,
        "description": "The Extreme battle against Barbariccia, archfiend of wind.",
        "slug": "storms-crown-extreme",
        "fflogs_zone_id": 50,
        "fflogs_encounters": [
          {
            "key": "barbariccia-ex",
            "name": "Barbariccia",
            "encounter_id": 1061
          }
        ]
      },
      {
        "Name": "Mount Ordeals (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Mount_Ordeals_%28Extreme%29.png",
        "tags": "Rubicante EX, EX5",
        "min_ilvl": 610,
        "description": "The Extreme battle against Rubicante, archfiend of fire.",
        "slug": "mount-ordeals-extreme",
        "fflogs_zone_id": 50,
        "fflogs_encounters": [
          {
            "key": "rubicante-ex",
            "name": "Rubicante",
            "encounter_id": 1062
          }
        ]
      },
      {
        "Name": "The Voidcast Dais (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Voidcast_Dais_%28Extreme%29.png",
        "tags": "Golbez EX, EX6",
        "min_ilvl": 630,
        "description": "The Extreme battle against Golbez atop the Voidcast Dais.",
        "slug": "the-voidcast-dais-extreme",
        "fflogs_zone_id": 55,
        "fflogs_encounters": [
          {
            "key": "golbez-ex",
            "name": "Golbez",
            "encounter_id": 1063
          }
        ]
      },
      {
        "Name": "The Abyssal Fracture (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Abyssal_Fracture_%28Extreme%29.png",
        "tags": "Zeromus EX, EX7",
        "min_ilvl": 640,
        "description": "The Extreme battle against Zeromus in the Abyssal Fracture.",
        "slug": "the-abyssal-fracture-extreme",
        "fflogs_zone_id": 55,
        "fflogs_encounters": [
          {
            "key": "zeromus-ex",
            "name": "Zeromus",
            "encounter_id": 1064
          }
        ]
      }
    ],
    "Dawntrail": [
      {
        "Name": "Worqor Lar Dor (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Worqor_Lar_Dor_%28Extreme%29.png",
        "tags": "Valigarmanda EX, EX1",
        "min_ilvl": 690,
        "description": "The Extreme battle against Valigarmanda, the Skyruin.",
        "slug": "worqor-lar-dor-extreme",
        "fflogs_zone_id": 58,
        "fflogs_encounters": [
          {
            "key": "valigarmanda-ex",
            "name": "Valigarmanda",
            "encounter_id": 1071
          }
        ]
      },
      {
        "Name": "Everkeep (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Everkeep_%28Extreme%29.png",
        "tags": "Zoraal Ja EX, EX2",
        "min_ilvl": 690,
        "description": "The Extreme battle against Zoraal Ja in Everkeep.",
        "slug": "everkeep-extreme",
        "fflogs_zone_id": 58,
        "fflogs_encounters": [
          {
            "key": "zoraal-ja-ex",
            "name": "Zoraal Ja",
            "encounter_id": 1072
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Sphene's Burden",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Sphene%27s_Burden.png",
        "tags": "Sphene EX, EX3",
        "min_ilvl": 710,
        "description": "The minstrel's retelling of the battle against Queen Eternal.",
        "slug": "the-minstrels-ballad-sphenes-burden",
        "fflogs_zone_id": 58,
        "fflogs_encounters": [
          {
            "key": "queen-eternal-ex",
            "name": "Queen Eternal",
            "encounter_id": 1073
          }
        ]
      },
      {
        "Name": "Recollection (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Recollection_%28Extreme%29.png",
        "tags": "Zelenia EX, EX4",
        "min_ilvl": 730,
        "description": "The Extreme battle against Zelenia.",
        "slug": "recollection-extreme",
        "fflogs_zone_id": 67,
        "fflogs_encounters": [
          {
            "key": "zelenia-ex",
            "name": "Zelenia",
            "encounter_id": 1080
          }
        ]
      },
      {
        "Name": "The Minstrel's Ballad: Necron's Embrace",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Minstrel%27s_Ballad_Necron%27s_Embrace.png",
        "tags": "Necron EX, EX5",
        "min_ilvl": 740,
        "description": "The minstrel's retelling of the battle against Necron.",
        "slug": "the-minstrels-ballad-necrons-embrace",
        "fflogs_zone_id": 67,
        "fflogs_encounters": [
          {
            "key": "necron-ex",
            "name": "Necron",
            "encounter_id": 1081
          }
        ]
      },
      {
        "Name": "The Windward Wilds (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Windward_Wilds_%28Extreme%29.png",
        "tags": "Arkveld EX, EX6",
        "min_ilvl": 740,
        "description": "The Extreme battle against Guardian Arkveld.",
        "slug": "the-windward-wilds-extreme",
        "fflogs_zone_id": 67,
        "fflogs_encounters": [
          {
            "key": "guardian-arkveld-ex",
            "name": "Guardian Arkveld",
            "encounter_id": 1082
          }
        ]
      },
      {
        "Name": "Hell on Rails (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Hell_on_Rails_%28Extreme%29.png",
        "tags": "Doomtrain EX, EX7",
        "min_ilvl": 760,
        "description": "The Extreme battle against Doomtrain.",
        "slug": "hell-on-rails-extreme",
        "fflogs_zone_id": 72,
        "fflogs_encounters": [
          {
            "key": "doomtrain-ex",
            "name": "Doomtrain",
            "encounter_id": 1083
          }
        ]
      },
      {
        "Name": "The Unmaking (Extreme)",
        "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/The_Unmaking_%28Extreme%29.png",
        "tags": "Enuo EX, EX8",
        "min_ilvl": 770,
        "description": "The Extreme battle against Enuo.",
        "slug": "the-unmaking-extreme",
        "fflogs_zone_id": 72,
        "fflogs_encounters": [
          {
            "key": "enuo-ex",
            "name": "Enuo",
            "encounter_id": 1084
          }
        ]
      }
    ]
  }
}
JSON, true, flags: JSON_THROW_ON_ERROR);

        return $data['ExtremeTrials'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unrealTrialData(): array
    {
        $data = json_decode(<<<'JSON'
{
  "UnrealTrials": [
    {
      "Name": "Shinryu's Domain (Unreal)",
      "image_url": "https://ffxiv.consolegameswiki.com/wiki/Special:Redirect/file/Shinryu%27s_Domain_%28Unreal%29.png",
      "tags": "Shinryu Unreal",
      "min_ilvl": 690,
      "description": "The faux commander craves a tale of fierce battle raging above the clouds, putting you in mind of your clash with the mighty Shinryu.",
      "slug": "shinryus-domain-unreal",
      "fflogs_zone_id": 64,
      "fflogs_encounters": [
        {
          "key": "shinryu-unreal",
          "name": "Shinryu",
          "encounter_id": 3013
        }
      ]
    }
  ]
}
JSON, true, flags: JSON_THROW_ON_ERROR);

        return $data['UnrealTrials'];
    }

    /**
     * @param  array<string, mixed>  $trial
     * @return array<string, mixed>
     */
    private function trialActivityType(array $trial, string $difficulty, string $imageDirectory): array
    {
        $name = (string) $trial['Name'];
        $tags = $this->tagList((string) ($trial['tags'] ?? ''));
        $tagPrefix = $tags[0] ?? $name;
        $encounters = $this->validEncounters($trial['fflogs_encounters'] ?? []);
        $partySize = $this->partySizeForTrial((string) $trial['slug']);
        $slug = $difficulty === ActivityType::DIFFICULTY_UNREAL
            ? 'unreal-trial'
            : (string) $trial['slug'];

        return [
            'slug' => $slug,
            'draft_name' => $this->localizedSame($name),
            'draft_description' => $this->localizedSame(sprintf('%s: %s', $tagPrefix, (string) $trial['description'])),
            'draft_small_image_url' => $this->trialImageUrl($slug, (string) ($trial['image_url'] ?? ''), $imageDirectory),
            'draft_banner_image_url' => $this->trialImageUrl($slug, (string) ($trial['image_url'] ?? ''), $imageDirectory),
            'draft_difficulty' => $difficulty,
            'draft_default_min_item_level' => (int) $trial['min_ilvl'],
            'draft_bench_size' => $partySize,
            'draft_fflogs_zone_id' => $trial['fflogs_zone_id'] ?? null,
            'draft_layout_schema' => $this->trialLayoutSchema($partySize),
            'draft_slot_schema' => $this->trialSlotSchema($partySize),
            'draft_application_schema' => $this->trialApplicationSchema($partySize),
            'draft_progress_schema' => [
                'milestones' => $this->trialProgressMilestones($encounters),
            ],
            'draft_prog_points' => $this->trialProgPoints($encounters),
            'tags' => $tags,
        ];
    }

    /**
     * @param  array<int, mixed>  $encounters
     * @return array<int, array<string, mixed>>
     */
    private function validEncounters(array $encounters): array
    {
        return collect($encounters)
            ->filter(fn (mixed $encounter): bool => is_array($encounter) && filled($encounter['encounter_id'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $encounters
     * @return array<int, array<string, mixed>>
     */
    private function trialProgressMilestones(array $encounters): array
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
    private function trialProgPoints(array $encounters): array
    {
        return collect($encounters)
            ->map(fn (array $encounter): array => $this->progPoint(
                (string) $encounter['key'],
                $this->localizedSame((string) $encounter['name']),
            ))
            ->values()
            ->all();
    }

    private function partySizeForTrial(string $slug): int
    {
        return $slug === 'the-great-hunt-extreme' ? 4 : 8;
    }

    /**
     * @return array<string, mixed>
     */
    private function trialLayoutSchema(int $partySize): array
    {
        return [
            'groups' => [
                $this->group('party', ['en' => 'Party', 'de' => 'Gruppe', 'fr' => 'Equipe', 'ja' => 'PT'], $partySize),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trialSlotSchema(int $partySize): array
    {
        $fields = [
            $this->schemaField(
                key: 'character_class',
                label: ['en' => 'Character Class', 'de' => 'Klasse', 'fr' => 'Classe', 'ja' => 'ジョブ'],
                type: 'single_select',
                source: 'character_classes',
            ),
        ];

        if ($partySize === 8) {
            $fields[] = $this->schemaField(
                key: 'raid_position',
                label: ['en' => 'Raid Position', 'de' => 'Raid-Position', 'fr' => 'Position de raid', 'ja' => 'レイドポジション'],
                type: 'single_select',
                source: 'raid_positions',
            );
        }

        return $fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trialApplicationSchema(int $partySize): array
    {
        $fields = [
            $this->schemaField(
                key: 'preferred_character_classes',
                label: ['en' => 'Preferred Character Classes', 'de' => 'Bevorzugte Klassen', 'fr' => 'Classes preferees', 'ja' => '希望ジョブ'],
                type: 'multi_select',
                source: 'character_classes',
            ),
        ];

        if ($partySize === 8) {
            $fields[] = $this->schemaField(
                key: 'preferred_raid_positions',
                label: ['en' => 'Preferred Raid Positions', 'de' => 'Bevorzugte Raid-Positionen', 'fr' => 'Positions de raid preferees', 'ja' => '希望ポジション'],
                type: 'multi_select',
                source: 'raid_positions',
                acceptsAny: true,
                anyLabel: ['en' => 'Put Me Anywhere Coach', 'de' => 'Setz mich ein, wo du willst, Coach', 'fr' => 'Mets-moi où tu veux, coach', 'ja' => 'どこでもいいです'],
            );
        }

        return $fields;
    }

    /**
     * @return array<int, string>
     */
    private function tagList(string $tags): array
    {
        return collect(explode(',', $tags))
            ->map(fn (string $tag): string => trim($tag))
            ->filter(fn (string $tag): bool => filled($tag))
            ->unique()
            ->values()
            ->all();
    }

    private function trialImageUrl(string $slug, string $sourceUrl, string $directory): ?string
    {
        if ($sourceUrl === '') {
            return null;
        }

        $relativePath = sprintf('prereqimages/%s/%s.png', trim($directory, '/'), $slug);
        $absolutePath = public_path($relativePath);

        if (file_exists($absolutePath)) {
            return '/'.$relativePath;
        }

        try {
            $response = Http::connectTimeout(3)->timeout(8)->get($sourceUrl);

            if ($response->successful() && filled($response->body())) {
                $parentDirectory = dirname($absolutePath);

                if (! is_dir($parentDirectory)) {
                    mkdir($parentDirectory, 0755, true);
                }

                file_put_contents($absolutePath, $response->body());

                return '/'.$relativePath;
            }
        } catch (\Throwable) {
            // If the download is unavailable during seeding, keep the original source URL as a safe fallback.
        }

        return $sourceUrl;
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
    ): array {
        return [
            'key' => $key,
            'label' => $this->localized($label),
            'order' => $order,
            'fflogs_matcher' => [
                'type' => 'encounter',
                'encounter_id' => $encounterId,
                'phase_id' => null,
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
