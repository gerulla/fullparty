<?php

use App\Models\Activity;
use App\Models\ActivitySlot;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\Group;
use App\Models\PhantomJob;
use App\Models\User;
use App\Services\Groups\ActivityRosterSpreadsheetExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports a styled excel-compatible roster sheet', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'name' => 'Speed Group',
    ]);

    $characterClass = CharacterClass::query()->create([
        'name' => 'Paladin',
        'shorthand' => 'PLD',
        'icon_url' => '/role-icons/tank.png',
        'flaticon_url' => '/role-icons/tank.png',
        'role' => 'tank',
    ]);

    $phantomJobs = collect([
        'Geomancer',
        'Thief',
        'Ranger',
        'Bard',
        'Time Mage',
        'Oracle',
        'Berserker',
        'Mystic Knight',
    ])->mapWithKeys(fn (string $name) => [
        $name => PhantomJob::query()->create([
            'name' => $name,
            'max_level' => 100,
            'icon_url' => '/role-icons/healer.png',
            'black_icon_url' => '/role-icons/healer.png',
            'transparent_icon_url' => '/role-icons/healer.png',
            'sprite_url' => null,
        ]),
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'layout_schema' => [
            'groups' => [
                ['key' => 'party-a', 'label' => ['en' => 'A'], 'size' => 8],
                ['key' => 'party-b', 'label' => ['en' => 'B'], 'size' => 8],
                ['key' => 'party-c', 'label' => ['en' => 'C'], 'size' => 8],
                ['key' => 'party-d', 'label' => ['en' => '1'], 'size' => 8],
                ['key' => 'party-e', 'label' => ['en' => '2'], 'size' => 8],
                ['key' => 'party-f', 'label' => ['en' => '3'], 'size' => 8],
            ],
        ],
        'roster_summary_presets' => [[
            'key' => 'minimal-composition',
            'label' => ['en' => 'Minimal Composition'],
            'description' => ['en' => 'Required phantom jobs.'],
            'requirements' => [
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Geomancer']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-a', 'party-b', 'party-c'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Thief']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-a', 'party-b', 'party-c'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Ranger']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-a', 'party-b', 'party-c'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Bard']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-a', 'party-b', 'party-c'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Time Mage']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-a', 'party-b', 'party-c'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Geomancer']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-d', 'party-e', 'party-f'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Thief']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-d', 'party-e', 'party-f'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Ranger']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-d', 'party-e', 'party-f'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Bard']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-d', 'party-e', 'party-f'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Time Mage']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'slot_group_set',
                    'scope_group_keys' => ['party-d', 'party-e', 'party-f'],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Oracle']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'all_slots',
                    'scope_group_keys' => [],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Berserker']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'all_slots',
                    'scope_group_keys' => [],
                ],
                [
                    'source' => 'phantom_jobs',
                    'source_id' => $phantomJobs['Mystic Knight']->id,
                    'comparison' => 'at_least',
                    'target_count' => 1,
                    'scope_type' => 'all_slots',
                    'scope_group_keys' => [],
                ],
            ],
        ]],
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'organized_by_user_id' => $owner->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'Speed1',
        'starts_at' => now()->setDate(2026, 5, 24)->setTime(19, 0),
        'duration_hours' => 2.5,
    ]);

    $activity->slots()->delete();

    $mainCharacter = Character::factory()->primary()->create([
        'user_id' => $owner->id,
        'name' => 'Coco Kosaka',
        'world' => 'Ragnarok',
        'datacenter' => 'Chaos',
        'avatar_url' => null,
    ]);

    $benchCharacter = Character::factory()->create([
        'user_id' => $owner->id,
        'name' => 'Ara Vee',
        'world' => 'Twintania',
        'datacenter' => 'Light',
        'avatar_url' => null,
    ]);

    ActivitySlot::factory()
        ->for($activity)
        ->assignedTo($mainCharacter, $owner)
        ->create([
            'group_key' => 'party-a',
            'group_label' => ['en' => 'Party A'],
            'slot_key' => 'tank-1',
            'slot_label' => ['en' => 'Tank (1)'],
            'position_in_group' => 1,
            'sort_order' => 1,
            'is_host' => true,
        ])
        ->fieldValues()
        ->createMany([
            [
                'field_key' => 'character_class',
                'field_label' => ['en' => 'Class'],
                'field_type' => 'single_select',
                'source' => 'character_classes',
                'value' => ['id' => $characterClass->id],
            ],
            [
                'field_key' => 'phantom_job',
                'field_label' => ['en' => 'Phantom Job'],
                'field_type' => 'single_select',
                'source' => 'phantom_jobs',
                'value' => ['id' => $phantomJobs['Oracle']->id],
            ],
            [
                'field_key' => 'raid_position',
                'field_label' => ['en' => 'Raid Position'],
                'field_type' => 'single_select',
                'source' => 'static_options',
                'value' => ['key' => 'mt', 'label' => ['en' => 'MT']],
            ],
        ]);

    foreach ([
        ['key' => 'party-b', 'label' => 'Party B', 'sort_order' => 2],
        ['key' => 'party-c', 'label' => 'Party C', 'sort_order' => 3],
        ['key' => 'party-d', 'label' => 'Party 1', 'sort_order' => 4],
        ['key' => 'party-e', 'label' => 'Party 2', 'sort_order' => 5],
        ['key' => 'party-f', 'label' => 'Party 3', 'sort_order' => 6],
    ] as $slotDefinition) {
        ActivitySlot::factory()
            ->for($activity)
            ->create([
                'group_key' => $slotDefinition['key'],
                'group_label' => ['en' => $slotDefinition['label']],
                'slot_key' => $slotDefinition['key'].'-tank-1',
                'slot_label' => ['en' => 'Tank (1)'],
                'position_in_group' => 1,
                'sort_order' => $slotDefinition['sort_order'],
            ]);
    }

    ActivitySlot::factory()
        ->for($activity)
        ->assignedTo($benchCharacter, $owner)
        ->create([
            'group_key' => 'bench',
            'group_label' => ['en' => 'Bench'],
            'slot_key' => 'bench-1',
            'slot_label' => ['en' => 'Bench 1'],
            'position_in_group' => 1,
            'sort_order' => 7,
        ]);

    $response = $this->actingAs($owner)->get(route('groups.dashboard.activities.export-roster', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('Content-Disposition'))->toContain('.xlsx');

    $temporaryFile = tempnam(sys_get_temp_dir(), 'fullparty-export-test-');
    expect($temporaryFile)->not->toBeFalse();

    file_put_contents($temporaryFile, $response->getContent());

    $archive = new ZipArchive;
    $openResult = $archive->open($temporaryFile);

    expect($openResult)->toBeTrue();

    $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
    $stylesXml = $archive->getFromName('xl/styles.xml');

    expect($sheetXml)->not->toBeFalse()
        ->and($sheetXml)->toContain('Party A')
        ->and($sheetXml)->toContain('Party B')
        ->and($sheetXml)->toContain('Coco Kosaka')
        ->and($sheetXml)->toContain('PLD')
        ->and($sheetXml)->toContain('Oracle')
        ->and($sheetXml)->toContain('Bench')
        ->and($sheetXml)->toContain('Requirements')
        ->and($sheetXml)->toContain('A/B/C')
        ->and($sheetXml)->toContain('1/2/3')
        ->and($sheetXml)->toContain('Either')
        ->and($sheetXml)->toContain('1 Geomancer')
        ->and($sheetXml)->toContain('1 Thief')
        ->and($sheetXml)->toContain('1 Ranger')
        ->and($sheetXml)->toContain('1 Bard')
        ->and($sheetXml)->toContain('1 Time Mage')
        ->and($sheetXml)->toContain('1 Berserker')
        ->and($sheetXml)->toContain('1 Mystic Knight')
        ->and($sheetXml)->toContain('Classes')
        ->and($sheetXml)->toContain('Code')
        ->and($sheetXml)->toContain('Job')
        ->and($sheetXml)->toContain('Role')
        ->and($sheetXml)->toContain('Paladin')
        ->and($sheetXml)->toContain('Phantom Jobs')
        ->and($sheetXml)->toContain('<dataValidation type="list"')
        ->and($sheetXml)->toContain('<conditionalFormatting sqref=')
        ->and($sheetXml)->toContain('VLOOKUP')
        ->and($sheetXml)->toContain('COUNTIF')
        ->and($sheetXml)->toContain('Roster!$R$4:$R$4')
        ->and($sheetXml)->toContain('Roster!$V$4:$V$11');

    expect($stylesXml)->not->toBeFalse();
    expect(strpos($sheetXml, '<conditionalFormatting'))->toBeLessThan(strpos($sheetXml, '<dataValidations'));
    expect(strpos($stylesXml, '<cellStyles'))->toBeLessThan(strpos($stylesXml, '<dxfs'));

    expect($archive->locateName('xl/drawings/drawing1.xml'))->toBeFalse();
    expect($archive->locateName('xl/media/image1.png'))->toBeFalse();

    $archive->close();

    foreach (['de', 'fr', 'ja'] as $locale) {
        app()->setLocale($locale);
        $exporter = app(ActivityRosterSpreadsheetExportService::class);
        $payload = $exporter->build($activity->fresh());
        expect($payload['class_options'][0]['label'])->toBe(__('characters.jobs.classes.pld'));
        $geomancer = __('characters.jobs.phantom.geomancer');
        expect(collect($payload['phantom_job_options'])->pluck('value'))->toContain($geomancer);
        expect($payload['requirements'][0]['items'][0]['match_value'])->toBe($geomancer);

        file_put_contents($temporaryFile, $exporter->render($activity->fresh()));
        $archive->open($temporaryFile);
        $localizedSheet = $archive->getFromName('xl/worksheets/sheet1.xml');
        expect($localizedSheet)->toContain(htmlspecialchars(__('ui.requirements'), ENT_XML1));
        expect($localizedSheet)->toContain(htmlspecialchars($geomancer, ENT_XML1));
        expect($localizedSheet)->toContain(__('ui.roster').'!$R$4:$R$4');
        expect($localizedSheet)->toContain('COUNTIF');
        expect($archive->getFromName('xl/workbook.xml'))->toContain('name="'.__('ui.roster').'"');
        $archive->close();
    }
    @unlink($temporaryFile);
});

it('exports a roster even when the activity type has no requirements summary rows', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->open()->create([
        'owner_id' => $owner->id,
        'name' => 'Simple Group',
    ]);

    CharacterClass::query()->create([
        'name' => 'Warrior',
        'shorthand' => 'WAR',
        'icon_url' => '/role-icons/tank.png',
        'flaticon_url' => '/role-icons/tank.png',
        'role' => 'tank',
    ]);

    $version = ActivityTypeVersion::factory()->create([
        'layout_schema' => [
            'groups' => [
                ['key' => 'party-a', 'label' => ['en' => 'A'], 'size' => 1],
            ],
        ],
        'roster_summary_presets' => [],
    ]);

    $activity = Activity::factory()->create([
        'group_id' => $group->id,
        'organized_by_user_id' => $owner->id,
        'activity_type_version_id' => $version->id,
        'activity_type_id' => $version->activity_type_id,
        'title' => 'NoReqs',
        'starts_at' => now()->setDate(2026, 5, 24)->setTime(19, 0),
        'duration_hours' => 2,
    ]);

    $activity->slots()->delete();

    ActivitySlot::factory()
        ->for($activity)
        ->create([
            'group_key' => 'party-a',
            'group_label' => ['en' => 'Party A'],
            'slot_key' => 'tank-1',
            'slot_label' => ['en' => 'Tank (1)'],
            'position_in_group' => 1,
            'sort_order' => 1,
        ]);

    $response = $this->actingAs($owner)->get(route('groups.dashboard.activities.export-roster', [
        'group' => $group->slug,
        'activity' => $activity->id,
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
