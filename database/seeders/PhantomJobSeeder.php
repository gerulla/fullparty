<?php

namespace Database\Seeders;

use App\Models\PhantomJob;
use App\Services\ManagedImageStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class PhantomJobSeeder extends Seeder
{
    /**
     * Seed the application's phantom jobs.
     */
    public function run(): void
    {
        $managedImageStorage = app(ManagedImageStorage::class);

        Storage::disk('public')->deleteDirectory('phantom-jobs');

        foreach ($this->jobs() as $job) {
            PhantomJob::updateOrCreate(
                ['name' => $job['name']],
                [
                    'max_level' => $job['max_level'],
                    'icon_url' => $managedImageStorage->downloadImageIfPresent($job['blue_icon'], 'icon_url', 'phantom-jobs/icons'),
                    'black_icon_url' => $managedImageStorage->downloadImageIfPresent($job['black_icon'], 'black_icon_url', 'phantom-jobs/black-icons'),
                    'transparent_icon_url' => $managedImageStorage->downloadImageIfPresent($job['transparent_icon'], 'transparent_icon_url', 'phantom-jobs/transparent-icons'),
                    'sprite_url' => $managedImageStorage->downloadImageIfPresent($job['sprite_icon'], 'sprite_url', 'phantom-jobs/sprites'),
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function jobs(): array
    {
        return [
            [
                'name' => 'Phantom Knight',
                'max_level' => 6,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/a/a9/Phantom_Knight_Icon_2.png/24px-Phantom_Knight_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/3/3c/Phantom_Knight_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/0/EeAUQGbPRholS0vdUUpQy-xpMg.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/s/A3-SG0tFwZO9pVR75gUloJoSQ0.png',
            ],
            [
                'name' => 'Phantom Monk',
                'max_level' => 6,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/2/23/Phantom_Monk_Icon_2.png/24px-Phantom_Monk_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/1/10/Phantom_Monk_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/1/Ytrt4rKZ9VtkThzraLOpZDcTJM.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/7/dn7C191l0vq3TtiRFR944Fb-Hc.png',
            ],
            [
                'name' => 'Phantom Thief',
                'max_level' => 6,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/4/48/Phantom_Thief_Icon_2.png/24px-Phantom_Thief_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/7/7d/Phantom_Thief_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/q/yB4sS-edFgJZr9zkj_ZhHmrdVs.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/E/Yfd8jLT0bCygTbggkpZQy4ggqY.png',
            ],
            [
                'name' => 'Phantom Samurai',
                'max_level' => 5,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/3/3e/Phantom_Samurai_Icon_2.png/24px-Phantom_Samurai_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/c/c0/Phantom_Samurai_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/a/aC1ZyKgdlf4rm7EqsynZabqjk0.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/K/3-EIjmHiXIAXjta5tOD8w3FqGE.png',
            ],
            [
                'name' => 'Phantom Berserker',
                'max_level' => 3,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/1/1d/Phantom_Berserker_Icon_2.png/24px-Phantom_Berserker_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/c/c3/Phantom_Berserker_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/1/i7_i2o3SMpWm3UL9DADFNeG82E.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/X/rLD12b3ctDWjOOIGjyCLiaxFDw.png',
            ],
            [
                'name' => 'Phantom Ranger',
                'max_level' => 6,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/5/57/Phantom_Ranger_Icon_2.png/24px-Phantom_Ranger_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/7/78/Phantom_Ranger_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/C/Lac7FfO38xNmTl2qELQOKG4QQw.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/0/RdMuMVdDWzqguuOkhku6s6n80s.png',
            ],
            [
                'name' => 'Phantom Mystic Knight',
                'max_level' => 4,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/64/Phantom_Mystic_Knight_Icon_2.png/24px-Phantom_Mystic_Knight_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/3/3b/Phantom_Mystic_Knight_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/t/l5wEwRwz-8JznV9PqhM9eMTdg4.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/4/6kfthKEhpe-sQ_BvBDTcDMqol8.png',
            ],
            [
                'name' => 'Phantom Time Mage',
                'max_level' => 5,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/e/e1/Phantom_Time_Mage_Icon_2.png/24px-Phantom_Time_Mage_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/8/84/Phantom_Time_Mage_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/S/WdoHHqrJWPJCQDkLaT167PVg2M.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/n/ZIdqqxYvR36UyoNSD-LGQ4zyX0.png',
            ],
            [
                'name' => 'Phantom Chemist',
                'max_level' => 4,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/3/35/Phantom_Chemist_Icon_2.png/24px-Phantom_Chemist_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/f/fd/Phantom_Chemist_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/Y/6yGkfR7vOy3F6hgCyB-rzZgqEs.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/U/6IadmelelH4pbxErgBY5Fh5rMw.png',
            ],
            [
                'name' => 'Phantom Geomancer',
                'max_level' => 5,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/61/Phantom_Geomancer_Icon_2.png/24px-Phantom_Geomancer_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/2/26/Phantom_Geomancer_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/k/E0TfYJCHf9kTCc18Vo84oSnl78.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/6/LU5ckrWlmoWTkGvVftNfpPtufQ.png',
            ],
            [
                'name' => 'Phantom Bard',
                'max_level' => 4,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/a/ac/Phantom_Bard_Icon_2.png/24px-Phantom_Bard_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/7/73/Phantom_Bard_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/q/kdRxGWaIMcO29FjivHfD3zPB80.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/R/dbBP-BFbJnDjyZqHrA3i3b-Nb0.png',
            ],
            [
                'name' => 'Phantom Dancer',
                'max_level' => 4,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/62/Phantom_Dancer_Icon_2.png/24px-Phantom_Dancer_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/d/d7/Phantom_Dancer_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/w/t9R7wDwdNEmxZhY8emmSDkYTKg.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/n/84NMryQaaZ0r3zRyYp9HurQ0SY.png',
            ],
            [
                'name' => 'Phantom Oracle',
                'max_level' => 5,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/b/b2/Phantom_Oracle_Icon_2.png/24px-Phantom_Oracle_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/4/4d/Phantom_Oracle_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/x/Hj3YuDplfAB5jOKPpvPYVTMilY.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/g/M3CgKEMRjbXYLXx4wfcuJ64ong.png',
            ],
            [
                'name' => 'Phantom Cannoneer',
                'max_level' => 6,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/5/5b/Phantom_Cannoneer_Icon_2.png/24px-Phantom_Cannoneer_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/1/17/Phantom_Cannoneer_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/U/dQZpbJaqEgFhqnTmg6osWgBczA.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/S/G0meGtQ8_WR4Oz8YRpui6LYBqA.png',
            ],
            [
                'name' => 'Phantom Gladiator',
                'max_level' => 4,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/6/65/Phantom_Gladiator_Icon_2.png/24px-Phantom_Gladiator_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/c/c7/Phantom_Gladiator_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/I/G8r27uAMqTSbEgyfFcecG3cbTo.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/r/SgAkfkF7k2P1HBMlgD0pqamsIs.png',
            ],
            [
                'name' => 'Phantom Freelancer',
                'max_level' => 16,
                'blue_icon' => 'https://ffxiv.gamerescape.com/w/images/thumb/0/09/Phantom_Freelancer_Icon_2.png/24px-Phantom_Freelancer_Icon_2.png',
                'black_icon' => 'https://ffxiv.gamerescape.com/w/images/2/23/Phantom_Freelancer_Icon.png',
                'sprite_icon' => 'https://lds-img.finalfantasyxiv.com/h/X/zanwwu69-0p23AHZWlg5_Jhzc8.png',
                'transparent_icon' => 'https://lds-img.finalfantasyxiv.com/h/Z/BPP6fZ59aZG1vWV0FN_-DNtK9c.png',
            ],
        ];
    }
}
