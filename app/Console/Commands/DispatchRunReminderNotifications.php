<?php

namespace App\Console\Commands;

use App\Services\Notifications\RunNotificationService;
use Illuminate\Console\Command;

class DispatchRunReminderNotifications extends Command
{
    protected $signature = 'notifications:dispatch-run-reminders';

    protected $description = 'Dispatch due starting-soon and starting-now run notifications.';

    public function __construct(
        private readonly RunNotificationService $runNotificationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $counts = $this->runNotificationService->dispatchDueReminders();

        $this->info(sprintf(
            'Dispatched %d starting-soon reminder(s) and %d starting-now reminder(s).',
            $counts['starting_soon'],
            $counts['starting_now'],
        ));

        return self::SUCCESS;
    }
}
