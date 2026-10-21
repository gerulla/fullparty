<?php

use App\Events\UserNotificationsUpdated;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivityGroupFollowUp;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use App\Models\AuditLog;
use App\Models\Character;
use App\Models\DiscordGuildIntegration;
use App\Models\DiscordUserIntegration;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\IntegrationClient;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Groups\ActivityCompletionService;
use App\Services\Groups\GroupPersonalInvitationService;
use App\Services\Integrations\IntegrationWebhookDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\UsesPostgresConcurrencyDatabase;

// These fixtures must commit so independent processes can see them. RefreshDatabase
// wraps fixtures in an invisible transaction and cannot test cross-process races.
uses(UsesPostgresConcurrencyDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->group = Group::factory()->create(['join_mode' => Group::JOIN_MODE_INVITE_ONLY]);
    $this->recipient = User::factory()->create();
});

function contendGroupInvitationRequests(string $action, array $payload, Model $lock): array
{
    $configuration = DB::connection()->getConfig();
    config()->set('database.connections.invitation_barrier', $configuration);
    $barrier = DB::connection('invitation_barrier');
    $processes = [];
    $names = [];
    try {
        $barrier->beginTransaction();
        $barrier->table($lock->getTable())->where('id', $lock->id)->lockForUpdate()->first();
        foreach ([1, 2] as $index) {
            $name = 'fullparty-invite-test-'.Str::lower(Str::random(12)).'-'.$index;
            $names[] = $name;
            $process = new Process([PHP_BINARY, base_path('tests/Support/group-invitation-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => storage_path('framework/testing/unused-worker-config.php'),
            ]);
            $process->setInput(json_encode([...$payload, 'action' => $action, 'database' => $configuration, 'worker_name' => $name], JSON_THROW_ON_ERROR));
            $process->setTimeout(25);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        do {
            $blocked = DB::table('pg_stat_activity')->whereIn('application_name', $names)->where('wait_event_type', 'Lock')->count();
            if ($blocked === 2) {
                break;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Worker exited before contention: '.$process->getErrorOutput().$process->getOutput());
                }
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        expect($blocked)->toBe(2, 'Both requests must reach the database lock before it is released.');
        $barrier->commit();

        return array_map(function (Process $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

            return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR)['result'];
        }, $processes);
    } finally {
        if ($barrier->transactionLevel() > 0) {
            $barrier->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('invitation_barrier');
    }
}

function concurrentInvitationRun(Group $group, User $participant): Activity
{
    $version = ActivityTypeVersion::factory()->create([
        'application_schema' => [], 'slot_schema' => [], 'progress_schema' => ['milestones' => []], 'bench_size' => 0,
        'layout_schema' => ['groups' => [['key' => 'party-a', 'label' => ['en' => 'Party A'], 'size' => 1]]],
    ]);
    $run = Activity::factory()->create(['group_id' => $group->id, 'activity_type_version_id' => $version->id, 'status' => Activity::STATUS_ONGOING, 'is_public' => true]);
    $character = Character::factory()->create(['user_id' => $participant->id]);
    $slot = $run->slots()->firstOrFail();
    $slot->update(['assigned_character_id' => $character->id]);
    $application = ActivityApplication::create(['activity_id' => $run->id, 'user_id' => $participant->id, 'selected_character_id' => $character->id, 'status' => ActivityApplication::STATUS_APPROVED]);
    ActivitySlotAssignment::create([
        'activity_id' => $run->id, 'group_id' => $group->id, 'activity_slot_id' => $slot->id, 'character_id' => $character->id,
        'application_id' => $application->id, 'attendance_status' => ActivitySlotAssignment::STATUS_CHECKED_IN, 'assigned_at' => now()->subHour(),
    ]);

    return $run;
}

it('serializes simultaneous sends into one invite notification and audit', function () {
    $results = contendGroupInvitationRequests('send', ['group_id' => $this->group->id, 'recipient_id' => $this->recipient->id, 'actor_id' => $this->group->owner_id], $this->group);
    expect($results[0])->toBe($results[1])
        ->and(GroupInvite::whereNotNull('recipient_user_id')->count())->toBe(1)
        ->and(NotificationEvent::where('type', 'groups.personal_invite')->count())->toBe(1)
        ->and(AuditLog::where('action', 'group.personal_invite.sent')->count())->toBe(1);
});

it('serializes simultaneous accepts at the membership quota into one membership and audit', function () {
    $invite = app(GroupPersonalInvitationService::class)->send($this->group, $this->recipient, $this->group->owner);
    $results = contendGroupInvitationRequests('accept', ['invite_id' => $invite->id, 'actor_id' => $this->recipient->id], $this->recipient);
    expect($results)->toBe([$this->group->id, $this->group->id])
        ->and($this->group->memberships()->where('user_id', $this->recipient->id)->count())->toBe(1)
        ->and($invite->fresh()->uses)->toBe(1)
        ->and(AuditLog::where('action', 'group.personal_invite.accepted')->count())->toBe(1);
});

it('lets only one simultaneous tab claim the automatic host prompt', function () {
    $run = concurrentInvitationRun($this->group, $this->recipient);
    app(ActivityCompletionService::class)->complete($run, [], $this->group->owner_id);
    $prompt = ActivityGroupFollowUp::where('kind', ActivityGroupFollowUp::INVITE_PARTICIPANTS)->sole();
    $results = contendGroupInvitationRequests('present', ['prompt_id' => $prompt->id, 'actor_id' => $this->group->owner_id], $prompt);
    sort($results);
    expect($results)->toBe([false, true]);
});

it('records simultaneous completions with one notification and one set of followups', function () {
    $run = concurrentInvitationRun($this->group, $this->recipient);
    contendGroupInvitationRequests('complete', ['activity_id' => $run->id, 'actor_id' => $this->group->owner_id], $run);
    expect(NotificationEvent::where('type', 'runs.completed')->count())->toBe(1)
        ->and(ActivityGroupFollowUp::where('activity_id', $run->id)->where('kind', ActivityGroupFollowUp::INVITE_CANDIDATE)->count())->toBe(1)
        ->and(AuditLog::where('action', 'group.activity.updated')->where('metadata->activity_id', $run->id)->count())->toBe(1);
});

it('broadcasts committed invitation inbox changes and drops rolled-back broadcasts', function () {
    $broadcasts = [];
    Event::listen(UserNotificationsUpdated::class, function ($event) use (&$broadcasts) {
        $broadcasts[] = $event->userId;
    });
    $service = app(GroupPersonalInvitationService::class);
    expect(fn () => DB::transaction(function () use ($service, &$broadcasts) {
        $service->send($this->group, $this->recipient, $this->group->owner);
        expect($broadcasts)->toBe([]);
        throw new RuntimeException('Rollback fixture');
    }))->toThrow(RuntimeException::class);
    expect($broadcasts)->toBe([])->and(NotificationEvent::where('type', 'groups.personal_invite')->count())->toBe(0);
    DB::transaction(function () use ($service, &$broadcasts) {
        $service->send($this->group, $this->recipient, $this->group->owner);
        expect($broadcasts)->toBe([]);
    });
    expect($broadcasts)->toBe([$this->recipient->id]);
});

it('only calls the Discord completion webhook after the completion commits', function () {
    $run = concurrentInvitationRun($this->group, $this->recipient);
    DiscordGuildIntegration::create(['group_id' => $this->group->id, 'discord_guild_id' => '123456789', 'guild_installed_at' => now()]);
    DiscordUserIntegration::create(['user_id' => $this->recipient->id, 'discord_user_id' => '987654321', 'user_app_installed_at' => now()]);
    $deliveries = [];
    $this->mock(IntegrationWebhookDispatcher::class)->shouldReceive('dispatchDiscordBotEvent')
        ->andReturnUsing(function ($event, $data) use (&$deliveries): array {
            expect(DB::transactionLevel())->toBe(0);
            if ($event === IntegrationClient::EVENT_DISCORD_GUILD_RUN_COMPLETED) {
                $deliveries[] = $data['run_id'];
            }

            return ['attempted' => 1, 'sent' => 1, 'failed' => 0, 'deliveries' => []];
        });
    $service = app(ActivityCompletionService::class);
    expect(fn () => DB::transaction(function () use ($service, $run, &$deliveries) {
        $service->completeAndNotify($run, [], $this->group->owner);
        expect($deliveries)->toBe([]);
        throw new RuntimeException('Rollback completion');
    }))->toThrow(RuntimeException::class);
    expect($deliveries)->toBe([])->and($run->fresh()->status)->toBe(Activity::STATUS_ONGOING);
    $service->completeAndNotify($run->fresh(), [], $this->group->owner);
    expect($deliveries)->toBe([$run->id]);
});
