<?php

namespace App\Console\Commands;

use App\Services\Auth\DiscordLoginWelcomeService;
use Illuminate\Console\Command;

final class DispatchPendingDiscordLoginWelcomes extends Command
{
    protected $signature = 'discord:dispatch-pending-welcomes';

    protected $description = 'Retry queueing Discord login welcomes that could not be queued.';

    public function handle(DiscordLoginWelcomeService $welcomes): int
    {
        $this->info(sprintf('Queued %d pending Discord welcome(s).', $welcomes->dispatchPending()));

        return self::SUCCESS;
    }
}
