<?php

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\PhantomComposition;
use App\Models\PhantomJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows moderators to manage Forked Tower phantom compositions', function () {
    $moderator = User::factory()->create();
    $group = Group::factory()
        ->withMember($moderator, GroupMembership::ROLE_MODERATOR)
        ->create();
    $bard = PhantomJob::query()->create(['name' => 'Phantom Bard', 'max_level' => 99]);
    $ranger = PhantomJob::query()->create(['name' => 'Phantom Ranger', 'max_level' => 99]);

    $existingDefault = PhantomComposition::query()->create([
        'group_id' => $group->id,
        'content_key' => PhantomComposition::CONTENT_FORKED_TOWER_BLOOD,
        'name' => 'Old default',
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 0,
        'rules' => [singleJobRule($bard)],
    ]);

    $response = $this->actingAs($moderator)
        ->postJson(
            route('groups.dashboard.content.forked-tower-blood.phantom-compositions.store', $group),
            phantomCompositionPayload($bard, $ranger),
        );

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'Balanced support')
        ->assertJsonPath('data.content_key', PhantomComposition::CONTENT_FORKED_TOWER_BLOOD)
        ->assertJsonPath('data.is_default', true)
        ->assertJsonPath('data.rules.2.type', PhantomComposition::RULE_PACKAGE)
        ->assertJsonPath('meta.states.2', PhantomComposition::STATE_PARTIAL)
        ->assertJsonCount(6, 'meta.slot_groups');

    expect($existingDefault->refresh()->is_default)->toBeFalse();

    $compositionId = (int) $response->json('data.id');

    $this->actingAs($moderator)
        ->getJson(route('groups.dashboard.content.forked-tower-blood.phantom-compositions.index', $group))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.id', $compositionId);

    $this->actingAs($moderator)
        ->putJson(route('groups.dashboard.content.forked-tower-blood.phantom-compositions.reorder', $group), [
            'composition_ids' => [$compositionId, $existingDefault->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.0.id', $compositionId)
        ->assertJsonPath('data.1.id', $existingDefault->id);

    expect((int) PhantomComposition::query()->findOrFail($compositionId)->sort_order)->toBe(0);
    expect((int) $existingDefault->refresh()->sort_order)->toBe(1);

    $this->actingAs($moderator)
        ->putJson(route('groups.dashboard.content.forked-tower-blood.phantom-compositions.update', [
            'group' => $group,
            'phantomComposition' => $compositionId,
        ]), [
            ...phantomCompositionPayload($bard, $ranger),
            'name' => 'Safer support',
            'is_default' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Safer support')
        ->assertJsonPath('data.is_default', false);

    $this->actingAs($moderator)
        ->deleteJson(route('groups.dashboard.content.forked-tower-blood.phantom-compositions.destroy', [
            'group' => $group,
            'phantomComposition' => $compositionId,
        ]))
        ->assertNoContent();

    $this->assertDatabaseMissing('phantom_compositions', [
        'id' => $compositionId,
    ]);
});

it('prevents regular members from managing phantom compositions', function () {
    $member = User::factory()->create();
    $group = Group::factory()
        ->withMember($member, GroupMembership::ROLE_MEMBER)
        ->create();
    $bard = PhantomJob::query()->create(['name' => 'Phantom Bard', 'max_level' => 99]);
    $ranger = PhantomJob::query()->create(['name' => 'Phantom Ranger', 'max_level' => 99]);

    $this->actingAs($member)
        ->postJson(
            route('groups.dashboard.content.forked-tower-blood.phantom-compositions.store', $group),
            phantomCompositionPayload($bard, $ranger),
        )
        ->assertForbidden();
});

it('allows moderators to save draft compositions without rules', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.content.forked-tower-blood.phantom-compositions.store', $group), [
            'name' => 'Draft composition',
            'rules' => [],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Draft composition')
        ->assertJsonCount(0, 'data.rules');
});

it('does not expose phantom compositions across groups', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);
    $otherGroup = Group::factory()->create(['owner_id' => $otherOwner->id]);
    $bard = PhantomJob::query()->create(['name' => 'Phantom Bard', 'max_level' => 99]);

    $composition = PhantomComposition::query()->create([
        'group_id' => $group->id,
        'content_key' => PhantomComposition::CONTENT_FORKED_TOWER_BLOOD,
        'name' => 'Private plan',
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 0,
        'rules' => [singleJobRule($bard)],
    ]);

    $this->actingAs($otherOwner)
        ->getJson(route('groups.dashboard.content.forked-tower-blood.phantom-compositions.show', [
            'group' => $otherGroup,
            'phantomComposition' => $composition,
        ]))
        ->assertNotFound();
});

it('validates phantom job rules and Forked Tower scopes', function () {
    $owner = User::factory()->create();
    $group = Group::factory()->create(['owner_id' => $owner->id]);

    $this->actingAs($owner)
        ->postJson(route('groups.dashboard.content.forked-tower-blood.phantom-compositions.store', $group), [
            'name' => 'Invalid plan',
            'rules' => [
                [
                    'type' => PhantomComposition::RULE_SINGLE_JOB_COUNT,
                    'severity' => PhantomComposition::SEVERITY_REQUIRED,
                    'comparison' => PhantomComposition::COMPARISON_AT_LEAST,
                    'target_count' => 1,
                    'scope' => [
                        'type' => PhantomComposition::SCOPE_SLOT_GROUP,
                        'group_keys' => ['party-z'],
                    ],
                    'phantom_job_id' => 9999,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'rules.0.scope.group_keys.0',
            'rules.0.phantom_job_id',
        ]);
});

it('backfills the default Forked Tower phantom compositions for existing groups', function () {
    $phantomJobs = createForkedTowerPhantomJobs();
    $firstGroup = Group::factory()->create();
    $secondGroup = Group::factory()->create();

    $existingRecommended = PhantomComposition::query()->create([
        'group_id' => $secondGroup->id,
        'content_key' => PhantomComposition::CONTENT_FORKED_TOWER_BLOOD,
        'name' => 'Recommended Composition',
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 7,
        'rules' => [],
    ]);

    $this->artisan('phantom-compositions:backfill-forked-tower', ['--dry-run' => true])
        ->assertSuccessful();

    expect(PhantomComposition::query()->count())->toBe(1);

    $this->artisan('phantom-compositions:backfill-forked-tower')
        ->assertSuccessful();

    expect(PhantomComposition::query()->count())->toBe(6);

    $firstGroupCompositions = PhantomComposition::query()
        ->where('group_id', $firstGroup->id)
        ->where('content_key', PhantomComposition::CONTENT_FORKED_TOWER_BLOOD)
        ->orderBy('sort_order')
        ->get();

    expect($firstGroupCompositions->pluck('name')->all())->toBe([
        'Minimal Composition',
        'Recommended Composition',
        'Risky Min-Maxing Composition',
    ]);

    $minimal = $firstGroupCompositions->firstWhere('name', 'Minimal Composition');
    $recommended = $firstGroupCompositions->firstWhere('name', 'Recommended Composition');
    $risky = $firstGroupCompositions->firstWhere('name', 'Risky Min-Maxing Composition');

    expect($minimal->is_default)->toBeTrue()
        ->and($minimal->rules)->toHaveCount(12)
        ->and($recommended->rules)->toHaveCount(16)
        ->and($risky->rules)->toHaveCount(14)
        ->and($minimal->rules[0])->toMatchArray([
            'type' => PhantomComposition::RULE_SINGLE_JOB_COUNT,
            'label' => 'Phantom Bard',
            'severity' => PhantomComposition::SEVERITY_REQUIRED,
            'comparison' => PhantomComposition::COMPARISON_AT_LEAST,
            'target_count' => 1,
            'phantom_job_id' => $phantomJobs['Phantom Bard']->id,
            'scope' => [
                'type' => PhantomComposition::SCOPE_SLOT_GROUP_SET,
                'group_keys' => ['party-a', 'party-b', 'party-c'],
            ],
        ]);

    expect($risky->rules[12])->toMatchArray([
        'label' => 'Phantom Berserker',
        'target_count' => 6,
        'phantom_job_id' => $phantomJobs['Phantom Berserker']->id,
        'scope' => ['type' => PhantomComposition::SCOPE_ALL_SLOTS],
    ]);

    expect($existingRecommended->refresh()->rules)->toBe([]);

    $this->artisan('phantom-compositions:backfill-forked-tower')
        ->assertSuccessful();

    expect(PhantomComposition::query()->count())->toBe(6);
});

it('fails the Forked Tower phantom composition backfill when required Phantom Jobs are missing', function () {
    Group::factory()->create();
    PhantomJob::query()->create(['name' => 'Phantom Bard', 'max_level' => 99]);

    $this->artisan('phantom-compositions:backfill-forked-tower')
        ->assertFailed();

    expect(PhantomComposition::query()->count())->toBe(0);
});

function phantomCompositionPayload(PhantomJob $bard, PhantomJob $ranger): array
{
    return [
        'name' => 'Balanced support',
        'description' => 'Baseline Forked Tower support coverage.',
        'is_default' => true,
        'is_active' => true,
        'sort_order' => 10,
        'rules' => [
            singleJobRule($bard),
            [
                'type' => PhantomComposition::RULE_JOB_SET_TOTAL,
                'label' => 'Support total',
                'severity' => PhantomComposition::SEVERITY_RECOMMENDED,
                'comparison' => PhantomComposition::COMPARISON_AT_LEAST,
                'target_count' => 2,
                'scope' => [
                    'type' => PhantomComposition::SCOPE_ALL_SLOTS,
                ],
                'phantom_job_ids' => [$bard->id, $ranger->id],
            ],
            [
                'type' => PhantomComposition::RULE_PACKAGE,
                'label' => 'Side packages',
                'severity' => PhantomComposition::SEVERITY_REQUIRED,
                'children' => [
                    [
                        'type' => PhantomComposition::RULE_EACH_JOB_IN_SET,
                        'label' => 'Each side support',
                        'severity' => PhantomComposition::SEVERITY_REQUIRED,
                        'comparison' => PhantomComposition::COMPARISON_AT_LEAST,
                        'target_count' => 1,
                        'scope' => [
                            'type' => PhantomComposition::SCOPE_EACH_SLOT_GROUP_SET,
                            'group_sets' => [
                                ['party-a', 'party-b', 'party-c'],
                                ['party-d', 'party-e', 'party-f'],
                            ],
                        ],
                        'phantom_job_ids' => [$bard->id, $ranger->id],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * @return array<string, PhantomJob>
 */
function createForkedTowerPhantomJobs(): array
{
    return collect([
        'Phantom Bard',
        'Phantom Ranger',
        'Phantom Thief',
        'Phantom Geomancer',
        'Phantom Time Mage',
        'Phantom Oracle',
        'Phantom Berserker',
        'Phantom Cannoneer',
        'Phantom Mystic Knight',
    ])
        ->mapWithKeys(fn (string $name) => [
            $name => PhantomJob::query()->create([
                'name' => $name,
                'max_level' => 99,
            ]),
        ])
        ->all();
}

function singleJobRule(PhantomJob $phantomJob): array
{
    return [
        'type' => PhantomComposition::RULE_SINGLE_JOB_COUNT,
        'label' => $phantomJob->name,
        'severity' => PhantomComposition::SEVERITY_REQUIRED,
        'comparison' => PhantomComposition::COMPARISON_AT_LEAST,
        'target_count' => 1,
        'scope' => [
            'type' => PhantomComposition::SCOPE_SLOT_GROUP_SET,
            'group_keys' => ['party-a', 'party-b', 'party-c'],
        ],
        'phantom_job_id' => $phantomJob->id,
    ];
}
