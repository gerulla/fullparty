<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait UsesPostgresConcurrencyDatabase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real lock contention requires PostgreSQL.');
        }
        if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'fullparty_test') {
            throw new RuntimeException('Only the isolated fullparty_test database is allowed.');
        }

        // Unlike transactional fixtures, committed fixtures must also be removed
        // before the next RefreshDatabase test starts its transaction.
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }
}
