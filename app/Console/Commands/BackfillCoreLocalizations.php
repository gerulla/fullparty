<?php

namespace App\Console\Commands;

use App\Models\ActivitySlot;
use App\Models\ActivitySlotFieldValue;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\Group;
use App\Support\SeedData\ActivityLocalizationCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BackfillCoreLocalizations extends Command
{
    protected $signature = 'localization:backfill {--dry-run : Preview changes without updating records}';

    protected $description = 'Translate known system defaults while preserving IDs, versions, schemas, and custom wording';

    public function handle(ActivityLocalizationCatalog $catalog): int
    {
        $fields = ['name', 'description', 'layout_schema', 'slot_schema', 'application_schema', 'roster_summary_presets', 'progress_schema', 'prog_points'];
        $models = [
            ActivityType::class => array_map(fn (string $field) => 'draft_'.$field, $fields),
            ActivityTypeVersion::class => $fields,
            Group::class => ['membership_application_schema'],
            ActivitySlot::class => ['group_label', 'filled_group_label', 'slot_label'],
            ActivitySlotFieldValue::class => ['field_label'],
        ];
        $counts = [];
        foreach ($models as $model => $attributes) {
            $counts[$model] = 0;
            $model::query()->select(['id', ...$attributes])->chunkById(100, function ($records) use ($model, $attributes, $catalog, &$counts) {
                foreach ($records as $record) {
                    if (! $this->translateRecord($record, $attributes, $catalog)) {
                        continue;
                    }
                    if ($this->option('dry-run')) {
                        $counts[$model]++;

                        continue;
                    }
                    DB::transaction(function () use ($record, $model, $attributes, $catalog, &$counts) {
                        /** @var Model|null $locked */
                        $locked = $model::query()->lockForUpdate()->find($record->id);
                        if (! $locked) {
                            return;
                        }
                        if ($this->translateRecord($locked, $attributes, $catalog)) {
                            $counts[$model]++;
                            // Translation maintenance must not republish or change historical timestamps.
                            $locked->timestamps = false;
                            $locked->saveQuietly();
                        }
                    });
                }
            });
        }
        $this->table(['Mode', 'Record type', 'Records'], collect($counts)->map(fn (int $count, string $model) => [
            $this->option('dry-run') ? 'Preview' : 'Updated', class_basename($model), $count,
        ])->values()->all());

        return self::SUCCESS;
    }

    private function translateRecord(Model $record, array $attributes, ActivityLocalizationCatalog $catalog): bool
    {
        foreach ($attributes as $attribute) {
            $value = $record->getAttribute($attribute);
            if (is_array($value)) {
                $record->setAttribute($attribute, $catalog->localize($value));
            }
        }

        return $record->isDirty();
    }
}
