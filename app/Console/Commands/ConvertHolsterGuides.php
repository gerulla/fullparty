<?php

namespace App\Console\Commands;

use App\Services\RichText\MarkdownGuideConverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ConvertHolsterGuides extends Command
{
    protected $signature = 'holsters:convert-guides {--apply : Back up and apply the validated conversion}';

    protected $description = 'Validate conversion of Markdown holster guides to Tiptap JSON; dry-run by default';

    public function handle(MarkdownGuideConverter $converter): int
    {
        $plan = [];
        $failed = false;
        foreach (DB::table('bozja_holsters')->where('guide_format', 'markdown')->orderBy('id')->lazyById(100) as $holster) {
            try {
                $plan[] = ['id' => $holster->id, 'guide' => $holster->guide, 'converted' => json_encode($converter->convert($holster->guide), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            } catch (Throwable $error) {
                $failed = true;
                $this->error("Holster {$holster->id}: {$error->getMessage()}");
            }
        }
        if ($failed) {
            $this->error('No guides changed. Resolve the reported content before retrying.');

            return self::FAILURE;
        }
        $this->info(count($plan).' guides validated.');
        if (! $this->option('apply') || $plan === []) {
            $this->info('No guides changed. Use --apply to back up and convert.');

            return self::SUCCESS;
        }
        $backup = 'backups/holster-guides/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        if (! Storage::disk('local')->put($backup, json_encode(['format' => 'markdown', 'created_at' => now()->toIso8601String(), 'guides' => array_map(fn ($row) => ['id' => $row['id'], 'guide' => $row['guide']], $plan)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))) {
            $this->error('Could not write the backup. No guides changed.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($plan): void {
                foreach ($plan as $row) {
                    $current = DB::table('bozja_holsters')->where('id', $row['id'])->lockForUpdate()->first();
                    if (! $current || $current->guide_format !== 'markdown' || $current->guide !== $row['guide']) {
                        throw new RuntimeException("Holster {$row['id']} changed during conversion. Run the command again.");
                    }
                    DB::table('bozja_holsters')->where('id', $row['id'])->update(['guide' => $row['converted'], 'guide_format' => 'tiptap']);
                }
            });
        } catch (Throwable $error) {
            $this->error($error->getMessage().' No guides changed.');

            return self::FAILURE;
        }
        $this->info('Conversion complete. Backup: '.Storage::disk('local')->path($backup));

        return self::SUCCESS;
    }
}
