<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityType;
use App\Models\Character;
use App\Models\Group;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Additive, local-only homepage fixtures for the Giki Chomusuke preview account. */
class HomePreviewSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        abort_unless(app()->environment('local'), 403);

        $character = Character::where('name', 'Giki Chomusuke')->whereNotNull('user_id')->sole();
        $user = $character->user;
        $groups = Group::query()
            ->where(fn ($query) => $query->where('owner_id', $user->id)
                ->orWhereHas('memberships', fn ($members) => $members->where('user_id', $user->id)))
            ->whereDoesntHave('bans', fn ($bans) => $bans->where('user_id', $user->id))
            ->withCount('memberships')->orderByDesc('memberships_count')->get();
        $owned = $groups->firstWhere('owner_id', $user->id);
        $others = $groups->where('owner_id', '!=', $user->id)->take(2)->values();
        $types = ActivityType::whereIn('slug', ['forked-tower', 'delubrum-reginae-savage', 'cloud-of-darkness-chaotic'])
            ->whereNotNull('current_published_version_id')->with('currentPublishedVersion')->get();
        if (! $owned || $others->isEmpty() || $types->isEmpty()) {
            throw new \RuntimeException('The preview account needs an owned group, another membership, and published activity types.');
        }

        $fixtures = [
            ['upcoming-learning', 'Learning night', 4, 'pending'],
            ['upcoming-reclear', 'Weekly reclear', 22, 'approved'],
            ['upcoming-reserve', 'Weekend reserve team', 40, 'on_bench'],
            ['upcoming-practice', 'Mechanics practice', 52, 'pending'],
            ['upcoming-farm', 'Relaxed farming', 70, 'approved'],
            ['upcoming-progression', 'Next boss progression', 94, 'pending'],
            ['hosted-first-clear', 'Giki’s first-clear workshop', 8, null],
            ['hosted-reclear', 'Giki’s weekly reclear', 28, null],
            ['hosted-learning', 'Giki’s learning party', 56, null],
            ['hosted-farm', 'Giki’s weekend farm', 80, null],
            ['group-catch-up', 'Community catch-up', 12, null],
            ['group-practice', 'Evening practice', 36, null],
            ['group-clear', 'Clear party', 60, null],
            ['group-weekend', 'Weekend community run', 108, null],
        ];
        $created = 0;
        DB::transaction(function () use ($fixtures, $character, $user, $owned, $others, $types, &$created): void {
            foreach ($fixtures as $index => [$key, $title, $hours, $status]) {
                $marker = 'homepage-preview:'.$user->id.':'.$key;
                if (Activity::where('settings->preview_seed', $marker)->exists()) {
                    continue;
                }
                $group = str_starts_with($key, 'hosted-') ? $owned : $others[$index % $others->count()];
                $type = $types[$index % $types->count()];
                $activity = Activity::factory()->create([
                    'group_id' => $group->id,
                    'activity_type_id' => $type->id,
                    'activity_type_version_id' => $type->current_published_version_id,
                    'organized_by_user_id' => $group->owner_id,
                    'organized_by_character_id' => $group->owner_id === $user->id ? $character->id : null,
                    'status' => Activity::STATUS_SCHEDULED,
                    'title' => $title,
                    'description' => 'Local preview run for the homepage redesign.',
                    'notes' => null,
                    'starts_at' => now()->addHours($hours)->startOfHour(),
                    'duration_hours' => $index % 2 ? 2 : 3,
                    'datacenter' => $group->datacenter,
                    'is_public' => $index % 3 !== 0,
                    'needs_application' => true,
                    'allow_guest_applications' => false,
                    'beginner_friendly' => $index % 3 === 0,
                    'run_style' => [Activity::RUN_STYLE_PROGRESSION, Activity::RUN_STYLE_RECLEAR, Activity::RUN_STYLE_FARM][$index % 3],
                    'settings' => ['preview_seed' => $marker],
                ]);
                if ($status !== null) {
                    $this->application($activity, $character, $status);
                }
                $applicants = Character::query()
                    ->whereNotNull('verified_at')->where('is_primary', true)
                    ->where('user_id', '!=', $user->id)
                    ->whereHas('user.groupMemberships', fn ($members) => $members->where('group_id', $group->id))
                    ->orderBy('id')->limit(3 + $index % 5)->get();
                foreach ($applicants as $applicant) {
                    $this->application($activity, $applicant, ActivityApplication::STATUS_PENDING);
                }
                $created++;
            }
        });

        $this->command?->info("Added {$created} homepage preview runs for {$character->name}.");
    }

    private function application(Activity $activity, Character $character, string $status): void
    {
        ActivityApplication::create([
            'activity_id' => $activity->id,
            'user_id' => $character->user_id,
            'selected_character_id' => $character->id,
            'status' => $status,
            'applicant_lodestone_id' => $character->lodestone_id,
            'applicant_character_name' => $character->name,
            'applicant_world' => $character->world,
            'applicant_datacenter' => $character->datacenter,
            'applicant_avatar_url' => $character->avatar_url,
            'submitted_at' => now(),
            'reviewed_at' => $status === ActivityApplication::STATUS_PENDING ? null : now(),
            'reviewed_by_user_id' => $status === ActivityApplication::STATUS_PENDING ? null : $activity->organized_by_user_id,
        ]);
    }
}
