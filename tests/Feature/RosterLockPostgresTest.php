<?php

use App\Models\Activity;
use App\Services\Groups\ActivityRosterLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('serializes competing PostgreSQL writers per run while leaving other runs unlocked', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL row-lock behavior is exercised by the PostgreSQL suite.');
    }

    $activity = Activity::factory()->create();
    $otherActivity = Activity::factory()->create();
    config(['database.connections.roster_competitor' => config('database.connections.pgsql')]);
    $competitor = DB::connection('roster_competitor');
    $competitor->statement("SET lock_timeout = '100ms'");

    try {
        app(ActivityRosterLock::class)->run($activity->id, function () use ($competitor, $activity, $otherActivity): void {
            $competitor->transaction(function () use ($competitor, $otherActivity): void {
                expect($competitor->table('activities')->where('id', $otherActivity->id)->lockForUpdate()->first())
                    ->not->toBeNull();
            });

            try {
                $competitor->transaction(fn () => $competitor->table('activities')
                    ->where('id', $activity->id)->lockForUpdate()->first());
                $this->fail('The competing writer acquired a lock held by another transaction.');
            } catch (QueryException $exception) {
                expect($exception->errorInfo[0])->toBe('55P03');
            }
        });

        $competitor->transaction(function () use ($competitor, $activity): void {
            expect($competitor->table('activities')->where('id', $activity->id)->lockForUpdate()->first())
                ->not->toBeNull();
        });
    } finally {
        DB::purge('roster_competitor');
    }
});
