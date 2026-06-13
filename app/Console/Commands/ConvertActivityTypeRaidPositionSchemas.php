<?php

namespace App\Console\Commands;

use App\Models\ActivityType;
use App\Models\RaidPosition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConvertActivityTypeRaidPositionSchemas extends Command
{
    protected $signature = 'activity-types:convert-raid-position-schemas
                            {--dry-run : Report matching activity types without writing changes}
                            {--slug=* : Limit conversion to one or more activity type slugs}';

    protected $description = 'Convert custom raid-position schema fields to the shared raid_positions source and publish new activity type versions.';

    private const ANY_LABEL = [
        'en' => 'Put Me Anywhere Coach',
        'de' => 'Setz mich ein, wo du willst, Coach',
        'fr' => 'Mets-moi où tu veux, coach',
        'ja' => 'どこでもいいです',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $slugs = collect($this->option('slug'))
            ->filter(fn (mixed $slug): bool => is_string($slug) && filled($slug))
            ->map(fn (string $slug): string => trim($slug))
            ->values();
        $raidPositionKeys = RaidPosition::query()
            ->where('is_active', true)
            ->pluck('key')
            ->map(fn (string $key): string => $key)
            ->values()
            ->all();

        if ($raidPositionKeys === []) {
            $this->error('No active raid positions exist. Seed or create raid positions before running this conversion.');

            return self::FAILURE;
        }

        $query = ActivityType::query()
            ->with('currentPublishedVersion');

        if ($slugs->isNotEmpty()) {
            $query->whereIn('slug', $slugs->all());
        }

        $converted = 0;
        $skipped = 0;

        $query->chunkById(50, function ($activityTypes) use ($dryRun, $raidPositionKeys, &$converted, &$skipped): void {
            foreach ($activityTypes as $activityType) {
                $publishedVersion = $activityType->currentPublishedVersion;
                $publishedSlotResult = $this->convertSchemaFields(
                    fields: $publishedVersion?->slot_schema ?? $activityType->draft_slot_schema ?? [],
                    isApplicationSchema: false,
                    raidPositionKeys: $raidPositionKeys,
                );
                $publishedApplicationResult = $this->convertSchemaFields(
                    fields: $publishedVersion?->application_schema ?? $activityType->draft_application_schema ?? [],
                    isApplicationSchema: true,
                    raidPositionKeys: $raidPositionKeys,
                );
                $draftSlotResult = $this->convertSchemaFields(
                    fields: $activityType->draft_slot_schema ?? [],
                    isApplicationSchema: false,
                    raidPositionKeys: $raidPositionKeys,
                );
                $draftApplicationResult = $this->convertSchemaFields(
                    fields: $activityType->draft_application_schema ?? [],
                    isApplicationSchema: true,
                    raidPositionKeys: $raidPositionKeys,
                );

                if (
                    ! $publishedSlotResult['changed']
                    && ! $publishedApplicationResult['changed']
                    && ! $draftSlotResult['changed']
                    && ! $draftApplicationResult['changed']
                ) {
                    $skipped++;
                    $this->line(sprintf('<comment>Skipped</comment> %s', $activityType->slug));

                    continue;
                }

                if ($dryRun) {
                    $converted++;
                    $this->line(sprintf('<info>Would convert</info> %s', $activityType->slug));

                    continue;
                }

                DB::transaction(function () use ($activityType, $publishedVersion, $publishedSlotResult, $publishedApplicationResult, $draftSlotResult, $draftApplicationResult): void {
                    $nextVersion = ((int) $activityType->versions()->max('version')) + 1;

                    $activityType->forceFill([
                        'draft_slot_schema' => $draftSlotResult['fields'],
                        'draft_application_schema' => $draftApplicationResult['fields'],
                    ])->save();

                    if (! $publishedSlotResult['changed'] && ! $publishedApplicationResult['changed']) {
                        return;
                    }

                    $version = $activityType->versions()->create([
                        'version' => $nextVersion,
                        'name' => $publishedVersion?->name ?? $activityType->draft_name,
                        'description' => $publishedVersion?->description ?? $activityType->draft_description,
                        'small_image_url' => $publishedVersion?->small_image_url ?? $activityType->draft_small_image_url,
                        'banner_image_url' => $publishedVersion?->banner_image_url ?? $activityType->draft_banner_image_url,
                        'difficulty' => $publishedVersion?->difficulty ?? $activityType->draft_difficulty,
                        'default_min_item_level' => $publishedVersion?->default_min_item_level ?? $activityType->draft_default_min_item_level,
                        'layout_schema' => $publishedVersion?->layout_schema ?? $activityType->draft_layout_schema,
                        'slot_schema' => $publishedSlotResult['fields'],
                        'application_schema' => $publishedApplicationResult['fields'],
                        'roster_summary_presets' => $publishedVersion?->roster_summary_presets ?? $activityType->draft_roster_summary_presets ?? [],
                        'progress_schema' => $publishedVersion?->progress_schema ?? $activityType->draft_progress_schema,
                        'bench_size' => $publishedVersion?->bench_size ?? $activityType->draft_bench_size ?? 0,
                        'prog_points' => $publishedVersion?->prog_points ?? $activityType->draft_prog_points ?? [],
                        'fflogs_zone_id' => $publishedVersion?->fflogs_zone_id ?? $activityType->draft_fflogs_zone_id,
                        'published_by_user_id' => null,
                        'published_at' => now(),
                    ]);

                    $activityType->forceFill([
                        'current_published_version_id' => $version->id,
                    ])->save();
                });

                $converted++;
                $this->line(sprintf('<info>Converted</info> %s', $activityType->slug));
            }
        });

        $this->newLine();
        $this->info(sprintf('%s: %d', $dryRun ? 'Would convert' : 'Converted', $converted));
        $this->line(sprintf('Skipped: %d', $skipped));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $fields
     * @param  array<int, string>  $raidPositionKeys
     * @return array{fields: array<int, mixed>, changed: bool}
     */
    private function convertSchemaFields(array $fields, bool $isApplicationSchema, array $raidPositionKeys): array
    {
        $changed = false;
        $convertedFields = collect($fields)
            ->map(function (mixed $field) use ($isApplicationSchema, $raidPositionKeys, &$changed): mixed {
                if (! is_array($field) || ! $this->isCustomRaidPositionField($field, $raidPositionKeys)) {
                    return $field;
                }

                $changed = true;
                $field['source'] = 'raid_positions';
                unset($field['options']);

                if ($isApplicationSchema) {
                    $field['accepts_any'] = true;
                    $field['any_label'] = self::ANY_LABEL;
                } else {
                    unset($field['accepts_any'], $field['any_label']);
                }

                return $field;
            })
            ->values()
            ->all();

        return [
            'fields' => $convertedFields,
            'changed' => $changed,
        ];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  $raidPositionKeys
     */
    private function isCustomRaidPositionField(array $field, array $raidPositionKeys): bool
    {
        if (($field['source'] ?? null) !== 'static_options') {
            return false;
        }

        if (! in_array((string) ($field['type'] ?? ''), ['single_select', 'multi_select'], true)) {
            return false;
        }

        $optionKeys = collect($field['options'] ?? [])
            ->filter(fn (mixed $option): bool => is_array($option))
            ->map(fn (array $option): string => (string) ($option['value'] ?? $option['key'] ?? ''))
            ->filter(fn (string $key): bool => filled($key))
            ->values();

        if ($optionKeys->isEmpty()) {
            return false;
        }

        $recognizedKeys = collect($raidPositionKeys)
            ->push('any')
            ->values()
            ->all();
        $unknownKeys = $optionKeys
            ->reject(fn (string $key): bool => in_array($key, $recognizedKeys, true));
        $matchedRaidPositionCount = $optionKeys
            ->filter(fn (string $key): bool => in_array($key, $raidPositionKeys, true))
            ->unique()
            ->count();

        if ($unknownKeys->isNotEmpty() || $matchedRaidPositionCount < 2) {
            return false;
        }

        $fieldKey = strtolower((string) ($field['key'] ?? ''));

        return str_contains($fieldKey, 'raid_position')
            || str_contains($fieldKey, 'position')
            || $matchedRaidPositionCount >= min(4, count($raidPositionKeys));
    }
}
