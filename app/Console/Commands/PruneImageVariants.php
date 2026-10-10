<?php

namespace App\Console\Commands;

use App\Services\Images\ImageVariantService;
use Illuminate\Console\Command;

class PruneImageVariants extends Command
{
    protected $signature = 'images:prune-variants';

    protected $description = 'Remove expired image variants and enforce the image cache size budget';

    public function handle(ImageVariantService $images): int
    {
        $this->info('Removed '.$images->prune().' cached image variants.');

        return self::SUCCESS;
    }
}
