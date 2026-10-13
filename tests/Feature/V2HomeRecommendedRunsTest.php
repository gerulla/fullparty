<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityTypeVersion;
use App\Models\Group;
use App\Models\User;
use App\Services\Dashboard\V2HomeDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
    $this->user = User::factory()->create();
    $this->group = Group::factory()->create(['is_visible' => true]);
    $this->version = ActivityTypeVersion::factory()->create([
        'layout_schema' => [],
        'prog_points' => [['key' => 'start', 'label' => ['en' => 'From Start', 'de' => 'Von Anfang an']]],
    ]);
    $this->run = Activity::factory()->create([
        'group_id' => $this->group->id,
        'activity_type_version_id' => $this->version->id,
        'status' => Activity::STATUS_SCHEDULED,
        'starts_at' => now()->addDay(),
        'is_public' => true,
        'needs_application' => true,
        'allow_guest_applications' => true,
        'target_prog_point_key' => 'start',
    ]);
});

it('provides the published progress label and localized attendee links for a recommended card', function () {
    app()->setLocale('de');
    $run = app(V2HomeDataService::class)->recommendedRuns($this->user)[0];
    $parameters = ['locale' => 'de', 'group' => $this->group->slug, 'activity' => $this->run->id];

    expect($run['target_prog_point_key'])->toBe('start')
        ->and($run['target_prog_point_label'])->toBe($this->version->prog_points[0]['label'])
        ->and($run['is_public'])->toBeTrue()
        ->and($run['allow_guest_applications'])->toBeTrue()
        ->and($run['has_existing_application'])->toBeFalse()
        ->and($run['can_apply'])->toBeTrue()
        ->and($run['href'])->toBe(route('groups.activities.overview', $parameters, false))
        ->and($run['application_href'])->toBe(route('groups.activities.application', $parameters, false))
        ->and($run)->not->toHaveKey('secret_key');
});

it('offers view application only for the viewers non-withdrawn application', function (string $status) {
    ActivityApplication::factory()->create([
        'activity_id' => $this->run->id,
        'user_id' => $this->user->id,
        'status' => $status,
    ]);
    $run = app(V2HomeDataService::class)->recommendedRuns($this->user)[0];

    expect($run['has_existing_application'])->toBe($status !== ActivityApplication::STATUS_WITHDRAWN)
        ->and($run['can_apply'])->toBe($status === ActivityApplication::STATUS_WITHDRAWN)
        ->and($run['application_href'])->not->toBeNull()
        ->and($run)->not->toHaveKey('applications');
})->with(['pending', 'approved', 'on_bench', 'declined', 'cancelled', 'withdrawn']);

it('does not confuse another users application with the viewers application', function () {
    ActivityApplication::factory()->create(['activity_id' => $this->run->id]);
    $run = app(V2HomeDataService::class)->recommendedRuns($this->user)[0];

    expect($run['has_existing_application'])->toBeFalse()
        ->and($run['can_apply'])->toBeTrue()
        ->and($run)->not->toHaveKey('applications');
});

it('omits the apply action for runs that do not use applications or have reached their start', function (array $attributes) {
    $this->run->update($attributes);
    $run = app(V2HomeDataService::class)->recommendedRuns($this->user)[0];

    expect($run['can_apply'])->toBeFalse()->and($run['application_href'])->toBeNull();
})->with([
    'no application form' => fn () => ['needs_application' => false],
    'starts now' => fn () => ['starts_at' => now()],
]);

it('allows a recommendation without a configured progress target', function () {
    $this->run->update(['target_prog_point_key' => null]);
    $run = app(V2HomeDataService::class)->recommendedRuns($this->user)[0];

    expect($run['target_prog_point_key'])->toBeNull()->and($run['target_prog_point_label'])->toBeNull();
});

it('counts active applicants and limits avatar previews to three per recommended run', function () {
    $secondRun = $this->run->replicate();
    $secondRun->save();
    $expectedAvatars = [];

    foreach ([$this->run, $secondRun] as $activity) {
        // Inactive applications must not consume preview positions, even when they were created first.
        foreach (['withdrawn', 'declined', 'cancelled', 'pending', 'approved', 'on_bench', 'pending', 'approved'] as $index => $status) {
            $application = ActivityApplication::factory()->create([
                'activity_id' => $activity->id,
                'status' => $status,
                'created_at' => now()->subMinutes(10 - $index),
            ]);
            $avatar = "https://example.test/character-{$application->id}.png";
            $application->update(['applicant_avatar_url' => $avatar]);
            if (in_array($index, [3, 4, 5], true)) {
                $expectedAvatars[$activity->id][] = $avatar;
            }
        }
    }

    $runs = app(V2HomeDataService::class)->recommendedRuns($this->user);
    expect($runs)->toHaveCount(2);
    foreach ($runs as $run) {
        expect($run['application_count'])->toBe(5)
            ->and($run['applicant_avatars'])->toBe($expectedAvatars[$run['id']])
            ->and($run)->not->toHaveKey('applications');
    }
});

it('keeps private profiles and guests anonymous in the applicant preview', function (array $privacy) {
    $applicant = $privacy ? User::factory()->create($privacy) : null;
    $application = ActivityApplication::factory()->create([
        'activity_id' => $this->run->id,
        'user_id' => $applicant?->id,
    ]);
    $application->update(['applicant_avatar_url' => 'https://example.test/private-character.png']);

    $run = app(V2HomeDataService::class)->recommendedRuns($this->user)[0];
    expect($run['application_count'])->toBe(1)
        ->and($run['applicant_avatars'])->toBe([null]);
})->with([
    'private profile' => [['public_profile' => false, 'public_characters' => true]],
    'private characters' => [['public_profile' => true, 'public_characters' => false]],
    'guest' => [[]],
]);

it('returns an empty avatar preview and zero count when nobody has applied', function () {
    $run = app(V2HomeDataService::class)->recommendedRuns($this->user)[0];
    expect($run['application_count'])->toBe(0)->and($run['applicant_avatars'])->toBe([]);
});
