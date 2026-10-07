<?php

namespace App\Console\Commands;

use App\Services\Notifications\AdminReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TestAdminReport extends Command
{
    protected $signature = 'admin:report-test {--queued : Send through the queue instead of contacting the bot immediately}';

    protected $description = 'Send a clearly labelled test admin report through the configured Discord bot.';

    public function handle(AdminReportService $reports): int
    {
        if (! config('services.admin_reports.enabled')) {
            $this->error(__('admin_reports.test_disabled'));

            return self::FAILURE;
        }

        $queued = (bool) $this->option('queued');
        $sent = $reports->report(
            key: 'admin-report-test:'.Str::uuid(),
            titleKey: 'admin_reports.test_title',
            messageKey: 'admin_reports.test_message',
            severity: 'info',
            immediate: ! $queued,
        );

        if (! $sent) {
            $this->error(__('admin_reports.test_failed'));

            return self::FAILURE;
        }

        $this->info(__($queued ? 'admin_reports.test_queued' : 'admin_reports.test_sent'));

        return self::SUCCESS;
    }
}
