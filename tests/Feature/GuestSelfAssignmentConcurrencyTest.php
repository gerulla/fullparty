<?php

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlotAssignment;
use App\Models\ActivityTypeVersion;
use App\Models\Character;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\UsesPostgresConcurrencyDatabase;

uses(UsesPostgresConcurrencyDatabase::class);

it('serializes guest bookings without duplicate characters or overwriting an occupant', function (bool $sameSlot) {
    Queue::fake();
    Http::preventStrayRequests();
    $version = ActivityTypeVersion::factory()->create([
        'slot_schema' => [], 'application_schema' => [], 'bench_size' => 0,
        'layout_schema' => ['groups' => [['key' => 'party', 'label' => ['en' => 'Party'], 'size' => 2]]],
    ]);
    $run = Activity::factory()->create([
        'activity_type_version_id' => $version->id, 'status' => Activity::STATUS_SCHEDULED,
        'is_public' => true, 'needs_application' => false, 'allow_guest_applications' => true,
    ]);
    $slots = $run->slots()->orderBy('id')->get();
    $configuration = DB::connection()->getConfig();
    config()->set('database.connections.guest_barrier', $configuration);
    $barrier = DB::connection('guest_barrier');
    $processes = [];
    $names = [];
    try {
        $barrier->beginTransaction();
        $barrier->table('activities')->where('id', $run->id)->lockForUpdate()->first();
        foreach ([0, 1] as $index) {
            $slot = $slots[$sameSlot ? 0 : $index];
            $name = 'fullparty-guest-test-'.Str::lower(Str::random(12));
            $names[] = $name;
            $process = new Process([PHP_BINARY, base_path('tests/Support/guest-self-assignment-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => storage_path('framework/testing/unused-worker-config.php'),
            ]);
            $process->setInput(json_encode([
                'database' => $configuration, 'worker_name' => $name, 'activity_id' => $run->id, 'slot_id' => $slot->id,
                'input' => [
                    'guest_applicant' => ['lodestone_id' => (string) (47431834 + ($sameSlot ? $index : 0)),
                        'name' => 'Guest Adventurer', 'world' => 'Lich', 'datacenter' => 'Light'],
                    'expected_slot_state_token' => activity_slot_state_token($slot),
                ],
            ], JSON_THROW_ON_ERROR));
            $process->setTimeout(25)->start();
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
        expect($blocked)->toBe(2);
        $barrier->commit();
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
            $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        expect($statuses)->toBe([201, $sameSlot ? 409 : 422])
            ->and(ActivityApplication::count())->toBe(1)
            ->and(ActivitySlotAssignment::whereNull('ended_at')->count())->toBe(1)
            ->and($run->slots()->whereNotNull('assigned_character_id')->count())->toBe(1)
            ->and(Character::where('add_method', 'guest_application')->count())->toBe(1);
    } finally {
        if ($barrier->transactionLevel() > 0) {
            $barrier->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('guest_barrier');
    }
})->with([true, false]);
