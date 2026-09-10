<?php

namespace App\Console\Commands;

use App\Services\Groups\Resources\ResourceImageService;
use Illuminate\Console\Command;

class CleanupResourceImages extends Command
{
    protected $signature = 'resources:cleanup-images';

    protected $description = 'Remove abandoned resource uploads, preserving working copies and retained revisions';

    public function handle(ResourceImageService $images): int
    {
        $this->info('Removed '.$images->cleanup().' abandoned resource images.');

        return self::SUCCESS;
    }
}
