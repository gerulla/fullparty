<?php

namespace App\Console\Commands;

use App\Services\Calculator\CalculatorDataImporter;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class ImportCalculatorData extends Command
{
    protected $signature = 'calculator:import-data {--path= : Override the CalculatorData source directory}';

    protected $description = 'Import the tracked calculator action, trait, buff, and potion data into system data';

    public function handle(CalculatorDataImporter $importer): int
    {
        try {
            $summary = $importer->import($this->option('path'));
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d calculator actions, %d calculator traits, %d calculator buffs, and %d calculator potions.',
            $summary['actions'],
            $summary['traits'],
            $summary['buffs'],
            $summary['potions'],
        ));

        if (
            $summary['stale_actions'] > 0
            || $summary['stale_traits'] > 0
            || $summary['stale_buffs'] > 0
            || $summary['stale_potions'] > 0
        ) {
            $this->warn(sprintf(
                'Marked %d stale calculator actions, %d stale calculator traits, %d stale calculator buffs, and %d stale calculator potions inactive.',
                $summary['stale_actions'],
                $summary['stale_traits'],
                $summary['stale_buffs'],
                $summary['stale_potions'],
            ));
        }

        return self::SUCCESS;
    }
}
