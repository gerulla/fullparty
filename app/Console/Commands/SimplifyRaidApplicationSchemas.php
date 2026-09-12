<?php

namespace App\Console\Commands;

use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SimplifyRaidApplicationSchemas extends Command
{
    protected $signature = 'activity-types:simplify-raid-applications
                            {--dry-run : Report matching activity types without writing changes}
                            {--slug=* : Limit the update to one or more activity type slugs}';

    protected $description = 'Remove recruitment-oriented questions from Extreme, Savage, and Ultimate application schemas and publish new versions.';

    private const TARGET_DIFFICULTIES = [
        ActivityType::DIFFICULTY_EXTREME,
        ActivityType::DIFFICULTY_SAVAGE,
        ActivityType::DIFFICULTY_ULTIMATE,
    ];

    private const REMOVED_FIELD_KEYS = [
        'relevant_experience',
        'fflogs_link',
        'lodestone_link',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $slugs = collect($this->option('slug'))
            ->filter(fn (mixed $slug): bool => is_string($slug) && filled($slug))
            ->map(fn (string $slug): string => trim($slug))
            ->values();
        $query = ActivityType::query()
            ->with('currentPublishedVersion')
            ->whereHas(
                'currentPublishedVersion',
                fn ($query) => $query->whereIn('difficulty', self::TARGET_DIFFICULTIES),
            );

        if ($slugs->isNotEmpty()) {
            $query->whereIn('slug', $slugs->all());
        }

        $published = 0;
        $draftOnly = 0;
        $skipped = 0;

        $query->chunkById(50, function ($activityTypes) use ($dryRun, &$published, &$draftOnly, &$skipped): void {
            foreach ($activityTypes as $activityType) {
                $publishedVersion = $activityType->currentPublishedVersion;

                if (! $publishedVersion instanceof ActivityTypeVersion) {
                    $skipped++;
                    $this->line(sprintf('<comment>Skipped unpublished type</comment> %s', $activityType->slug));

                    continue;
                }

                $publishedResult = $this->removeFields($publishedVersion->application_schema ?? []);
                $draftResult = $this->removeFields($activityType->draft_application_schema ?? []);

                if (! $publishedResult['changed'] && ! $draftResult['changed']) {
                    $skipped++;
                    $this->line(sprintf('<comment>Already clean</comment> %s', $activityType->slug));

                    continue;
                }

                if ($dryRun) {
                    if ($publishedResult['changed']) {
                        $published++;
                    } else {
                        $draftOnly++;
                    }

                    $this->line(sprintf(
                        '<info>Would %s</info> %s',
                        $publishedResult['changed'] ? 'publish' : 'update draft for',
                        $activityType->slug,
                    ));

                    continue;
                }

                $newVersion = DB::transaction(function () use ($activityType, $publishedVersion, $publishedResult, $draftResult): ?ActivityTypeVersion {
                    if ($draftResult['changed']) {
                        $activityType->forceFill([
                            'draft_application_schema' => $draftResult['schema'],
                        ])->save();
                    }

                    if (! $publishedResult['changed']) {
                        return null;
                    }

                    $version = $activityType->versions()->create([
                        'version' => ((int) $activityType->versions()->max('version')) + 1,
                        'name' => $publishedVersion->name,
                        'description' => $publishedVersion->description,
                        'small_image_url' => $publishedVersion->small_image_url,
                        'banner_image_url' => $publishedVersion->banner_image_url,
                        'difficulty' => $publishedVersion->difficulty,
                        'default_min_item_level' => $publishedVersion->default_min_item_level,
                        'layout_schema' => $publishedVersion->layout_schema,
                        'slot_schema' => $publishedVersion->slot_schema,
                        'application_schema' => $publishedResult['schema'],
                        'roster_summary_presets' => $publishedVersion->roster_summary_presets ?? [],
                        'progress_schema' => $publishedVersion->progress_schema,
                        'bench_size' => $publishedVersion->bench_size,
                        'prog_points' => $publishedVersion->prog_points ?? [],
                        'fflogs_zone_id' => $publishedVersion->fflogs_zone_id,
                        'published_by_user_id' => null,
                        'published_at' => now(),
                    ]);

                    $activityType->forceFill([
                        'current_published_version_id' => $version->id,
                    ])->save();

                    return $version;
                });

                if ($newVersion) {
                    $published++;
                    $this->line(sprintf('<info>Published v%d</info> %s', $newVersion->version, $activityType->slug));
                } else {
                    $draftOnly++;
                    $this->line(sprintf('<info>Updated draft</info> %s', $activityType->slug));
                }
            }
        });

        $this->newLine();
        $this->info(sprintf('%s: %d', $dryRun ? 'Would publish' : 'Published', $published));
        $this->line(sprintf('%s: %d', $dryRun ? 'Would update draft only' : 'Updated draft only', $draftOnly));
        $this->line(sprintf('Skipped: %d', $skipped));

        return self::SUCCESS;
    }

    /**
     * @param  array<int|string, mixed>  $schema
     * @return array{schema: array<int|string, mixed>, changed: bool}
     */
    private function removeFields(array $schema): array
    {
        if (array_is_list($schema)) {
            return $this->removeFieldsFromList($schema);
        }

        if (! isset($schema['questions']) || ! is_array($schema['questions'])) {
            return ['schema' => $schema, 'changed' => false];
        }

        $result = $this->removeFieldsFromList($schema['questions']);
        $schema['questions'] = $result['schema'];

        return ['schema' => $schema, 'changed' => $result['changed']];
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array{schema: array<int, mixed>, changed: bool}
     */
    private function removeFieldsFromList(array $fields): array
    {
        $filtered = collect($fields)
            ->reject(fn (mixed $field): bool => is_array($field)
                && in_array((string) ($field['key'] ?? ''), self::REMOVED_FIELD_KEYS, true))
            ->values()
            ->all();

        return [
            'schema' => $filtered,
            'changed' => count($filtered) !== count($fields),
        ];
    }
}
