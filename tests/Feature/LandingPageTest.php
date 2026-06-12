<?php

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders server-side embed meta for the landing page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta property="og:title" content="FFXIV group and roster planning - FullParty.gg">', false)
        ->assertSee('<meta property="og:image" content="http://fullparty.test/landing.png">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
});

it('shows discoverable this week runs with assigned member stack data', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-21 12:00:00'));

    try {
        $group = Group::factory()->open()->create([
            'name' => 'Public Group',
            'datacenter' => 'Light',
        ]);
        $hiddenGroup = Group::factory()->hidden()->create();
        $type = ActivityType::factory()->create([
            'slug' => 'arcadion',
        ]);
        $version = ActivityTypeVersion::factory()->create([
            'activity_type_id' => $type->id,
            'name' => ['en' => 'AAC Light-heavyweight M4S'],
            'difficulty' => ActivityType::DIFFICULTY_SAVAGE,
        ]);
        $type->update([
            'current_published_version_id' => $version->id,
        ]);

        $activity = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_SCHEDULED,
            'title' => 'Thursday Prog',
            'starts_at' => CarbonImmutable::parse('2026-05-21 20:00:00'),
            'is_public' => true,
            'allow_guest_applications' => false,
        ]);

        $completedActivity = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_COMPLETE,
            'title' => 'Monday Clear',
            'starts_at' => CarbonImmutable::parse('2026-05-18 20:00:00'),
            'is_public' => true,
            'allow_guest_applications' => true,
        ]);

        Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_CANCELLED,
            'title' => 'Cancelled Monday',
            'starts_at' => CarbonImmutable::parse('2026-05-18 21:00:00'),
            'is_public' => true,
            'allow_guest_applications' => true,
        ]);

        $guestFriendlyActivity = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_ASSIGNED,
            'title' => 'Guest Friendly Assigned',
            'starts_at' => CarbonImmutable::parse('2026-05-21 20:30:00'),
            'is_public' => true,
            'allow_guest_applications' => true,
        ]);

        Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_SCHEDULED,
            'title' => 'Visible Overflow',
            'starts_at' => CarbonImmutable::parse('2026-05-21 23:00:00'),
            'is_public' => true,
            'allow_guest_applications' => true,
        ]);

        Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_SCHEDULED,
            'title' => 'Members-only Thursday',
            'starts_at' => CarbonImmutable::parse('2026-05-21 21:00:00'),
            'is_public' => false,
        ]);

        Activity::factory()->create([
            'group_id' => $hiddenGroup->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_SCHEDULED,
            'title' => 'Hidden Thursday',
            'starts_at' => CarbonImmutable::parse('2026-05-21 22:00:00'),
            'is_public' => true,
        ]);

        $activity->slots()
            ->take(5)
            ->get()
            ->each(function ($slot, int $index): void {
                $character = Character::factory()->create([
                    'name' => sprintf('Assigned Player %d', $index + 1),
                    'avatar_url' => $index === 0 ? '/characters/char1.png' : null,
                ]);

                $slot->update([
                    'assigned_character_id' => $character->id,
                ]);
            });

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->where('landing.this_week.start', '2026-05-18')
                ->where('landing.this_week.end', '2026-05-24')
                ->where('landing.this_week.days.0.key', 'mon')
                ->where('landing.this_week.days.0.hidden_run_count', 0)
                ->has('landing.this_week.days.0.runs', 1)
                ->where('landing.this_week.days.0.runs.0.id', $completedActivity->id)
                ->where('landing.this_week.days.0.runs.0.application_status_key', 'closed')
                ->where('landing.this_week.days.0.runs.0.href', null)
                ->where('landing.this_week.days.3.key', 'thu')
                ->where('landing.this_week.days.3.is_today', true)
                ->where('landing.this_week.days.3.hidden_run_count', 2)
                ->has('landing.this_week.days.3.runs', 2)
                ->where('landing.this_week.days.3.runs.0.id', $activity->id)
                ->where('landing.this_week.days.3.runs.0.title', 'Thursday Prog')
                ->where('landing.this_week.days.3.runs.0.difficulty', ActivityType::DIFFICULTY_SAVAGE)
                ->where('landing.this_week.days.3.runs.0.datacenter', 'Light')
                ->where('landing.this_week.days.3.runs.0.application_status_key', 'open')
                ->where('landing.this_week.days.3.runs.0.allow_guest_applications', false)
                ->where('landing.this_week.days.3.runs.0.href', route('login', [
                    'locale' => app()->getLocale(),
                ]))
                ->where('landing.this_week.days.3.runs.0.filled_slots', 5)
                ->where('landing.this_week.days.3.runs.0.total_slots', 8)
                ->where('landing.this_week.days.3.runs.0.overflow_count', 1)
                ->has('landing.this_week.days.3.runs.0.assigned_members', 4)
                ->where('landing.this_week.days.3.runs.0.assigned_members.0.name', 'Assigned Player 1')
                ->where('landing.this_week.days.3.runs.0.assigned_members.0.avatar_url', '/characters/char1.png')
                ->where('landing.this_week.days.3.runs.1.id', $guestFriendlyActivity->id)
                ->where('landing.this_week.days.3.runs.1.application_status_key', 'open')
                ->where('landing.this_week.days.3.runs.1.allow_guest_applications', true)
                ->where('landing.this_week.days.3.runs.1.href', route('groups.activities.overview', [
                    'locale' => app()->getLocale(),
                    'group' => $group->slug,
                    'activity' => $guestFriendlyActivity->id,
                ]))
            );
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('links non guest-friendly landing runs to their overview for logged in users', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-21 12:00:00'));

    try {
        $user = User::factory()->create();
        $group = Group::factory()->open()->create();
        $type = ActivityType::factory()->create();
        $version = ActivityTypeVersion::factory()->create([
            'activity_type_id' => $type->id,
        ]);
        $type->update([
            'current_published_version_id' => $version->id,
        ]);
        $activity = Activity::factory()->create([
            'group_id' => $group->id,
            'activity_type_id' => $type->id,
            'activity_type_version_id' => $version->id,
            'status' => Activity::STATUS_SCHEDULED,
            'starts_at' => CarbonImmutable::parse('2026-05-21 20:00:00'),
            'is_public' => true,
            'allow_guest_applications' => false,
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('landing.this_week.days.3.runs.0.href', route('groups.activities.overview', [
                    'locale' => app()->getLocale(),
                    'group' => $group->slug,
                    'activity' => $activity->id,
                ]))
            );
    } finally {
        CarbonImmutable::setTestNow();
    }
});
