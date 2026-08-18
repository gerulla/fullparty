<?php

namespace App\Services\Calculator;

use App\Models\CalculatorAction;
use App\Models\CalculatorBuff;
use App\Models\CalculatorPotion;
use App\Models\CalculatorTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class CalculatorDataImporter
{
    private const LOCALE_FILES = [
        'en' => 'info.json',
        'de' => 'info.de.json',
        'fr' => 'info.fr.json',
        'ja' => 'info.ja.json',
    ];

    /**
     * @return array{actions: int, traits: int, buffs: int, potions: int, stale_actions: int, stale_traits: int, stale_buffs: int, stale_potions: int}
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    public function import(?string $sourceDirectory = null): array
    {
        $sourceDirectory = $this->resolveSourceDirectory($sourceDirectory);

        if (! is_dir($sourceDirectory)) {
            throw new RuntimeException("Calculator data directory not found: {$sourceDirectory}");
        }

        [$actionRecords, $traitRecords, $buffRecords, $potionRecords] = $this->readRecords($sourceDirectory);

        return DB::transaction(function () use ($actionRecords, $traitRecords, $buffRecords, $potionRecords): array {
            $now = now();

            foreach ($actionRecords as $record) {
                CalculatorAction::query()->updateOrCreate(
                    ['key' => $record['key']],
                    [...$record, 'updated_at' => $now],
                );
            }

            foreach ($traitRecords as $record) {
                CalculatorTrait::query()->updateOrCreate(
                    ['key' => $record['key']],
                    [...$record, 'updated_at' => $now],
                );
            }

            foreach ($buffRecords as $record) {
                CalculatorBuff::query()->updateOrCreate(
                    ['key' => $record['key']],
                    [...$record, 'updated_at' => $now],
                );
            }

            foreach ($potionRecords as $record) {
                CalculatorPotion::query()->updateOrCreate(
                    ['key' => $record['key']],
                    [...$record, 'updated_at' => $now],
                );
            }

            $staleActions = CalculatorAction::query()
                ->whereNotIn('key', array_column($actionRecords, 'key'))
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => $now]);

            $staleTraits = CalculatorTrait::query()
                ->whereNotIn('key', array_column($traitRecords, 'key'))
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => $now]);

            $staleBuffs = CalculatorBuff::query()
                ->whereNotIn('key', array_column($buffRecords, 'key'))
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => $now]);

            $stalePotions = CalculatorPotion::query()
                ->whereNotIn('key', array_column($potionRecords, 'key'))
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => $now]);

            return [
                'actions' => count($actionRecords),
                'traits' => count($traitRecords),
                'buffs' => count($buffRecords),
                'potions' => count($potionRecords),
                'stale_actions' => $staleActions,
                'stale_traits' => $staleTraits,
                'stale_buffs' => $staleBuffs,
                'stale_potions' => $stalePotions,
            ];
        });
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>, 3: array<int, array<string, mixed>>}
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private function readRecords(string $sourceDirectory): array
    {
        $actionRecords = [];
        $traitRecords = [];
        $buffRecords = [];
        $potionRecords = [];
        $jsonPaths = $this->findInfoJsonPaths($sourceDirectory);

        if ($jsonPaths === []) {
            throw new RuntimeException('No calculator info.json files were found.');
        }

        foreach ($jsonPaths as $jsonPath) {
            $payload = $this->readJsonFile($jsonPath);
            $kind = (string) ($payload['kind'] ?? '');
            $localizedPayloads = $this->readLocalizedPayloads(dirname($jsonPath), $kind);

            match ($kind) {
                'ability' => $actionRecords[] = $this->mapActionRecord($payload, $localizedPayloads, $jsonPath),
                'trait' => $traitRecords[] = $this->mapTraitRecord($payload, $localizedPayloads, $jsonPath),
                'combat_status' => $buffRecords[] = $this->mapBuffRecord($payload, $localizedPayloads, $jsonPath),
                'combat_potion' => $potionRecords[] = $this->mapPotionRecord($payload, $localizedPayloads, $jsonPath),
                default => throw new RuntimeException("Unsupported calculator data kind [{$kind}] in {$jsonPath}"),
            };
        }

        return [$actionRecords, $traitRecords, $buffRecords, $potionRecords];
    }

    /**
     * @return array<int, string>
     */
    private function findInfoJsonPaths(string $sourceDirectory): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getFilename() !== 'info.json') {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $localizedPayloads
     * @return array<string, mixed>
     */
    private function mapActionRecord(array $payload, array $localizedPayloads, string $jsonPath): array
    {
        $sourceId = $this->intValue(data_get($payload, 'id'));
        $role = (string) data_get($payload, 'role', 'Unknown');
        $jobId = $this->intValue(data_get($payload, 'job.id'));

        return [
            'key' => $this->stableKey('ability', $role, $jobId, $sourceId),
            'source_path' => $this->publicRelativePath($jsonPath),
            'source_hash' => $this->sourceHash($jsonPath),
            'source_id' => $sourceId,
            'kind' => (string) data_get($payload, 'kind'),
            'name' => (string) data_get($payload, 'name'),
            'name_translations' => $this->localizedField($localizedPayloads, 'name'),
            'description' => $this->nullableString(data_get($payload, 'description')),
            'description_translations' => $this->localizedField($localizedPayloads, 'description'),
            'description_macro' => $this->nullableString(data_get($payload, 'description_macro')),
            'description_macro_translations' => $this->localizedField($localizedPayloads, 'description_macro'),
            'effects' => $this->arrayValue(data_get($payload, 'effects')),
            'effects_translations' => $this->localizedField($localizedPayloads, 'effects'),
            'role' => $role,
            'job_id' => $jobId,
            'job_name' => (string) data_get($payload, 'job.name'),
            'job_abbreviation' => $this->nullableString(data_get($payload, 'job.abbreviation')),
            'is_phantom_action' => (bool) data_get($payload, 'is_phantom_action', false),
            'unlock_level' => $this->intValue(data_get($payload, 'unlock_level')),
            'icon_id' => $this->nullableInt(data_get($payload, 'icon.id')),
            'icon_file' => $this->nullableString(data_get($payload, 'icon.file')),
            'icon_url' => $this->iconUrl($jsonPath, $payload),
            'action_category_id' => $this->nullableInt(data_get($payload, 'action_category.id')),
            'action_category_name' => $this->nullableString(data_get($payload, 'action_category.name')),
            'attack_type_id' => $this->nullableInt(data_get($payload, 'attack_type.id')),
            'attack_type_name' => $this->nullableString(data_get($payload, 'attack_type.name')),
            'timing_cast_seconds' => $this->nullableFloat(data_get($payload, 'timing.cast_seconds')),
            'timing_recast_seconds' => $this->nullableFloat(data_get($payload, 'timing.recast_seconds')),
            'timing_extra_cast_seconds' => $this->nullableFloat(data_get($payload, 'timing.extra_cast_seconds')),
            'timing_cooldown_group' => $this->nullableInt(data_get($payload, 'timing.cooldown_group')),
            'timing_additional_cooldown_group' => $this->nullableInt(data_get($payload, 'timing.additional_cooldown_group')),
            'timing_max_charges' => $this->nullableInt(data_get($payload, 'timing.max_charges')),
            'cost_primary_type_id' => $this->nullableInt(data_get($payload, 'costs.primary.type_id')),
            'cost_primary_value' => $this->nullableInt(data_get($payload, 'costs.primary.value')),
            'cost_secondary_type_id' => $this->nullableInt(data_get($payload, 'costs.secondary.type_id')),
            'cost_secondary_value' => $this->nullableInt(data_get($payload, 'costs.secondary.value')),
            'range_target_yalms' => $this->nullableInt(data_get($payload, 'range.target_yalms')),
            'range_effect_yalms' => $this->nullableInt(data_get($payload, 'range.effect_yalms')),
            'range_cast_type' => $this->nullableInt(data_get($payload, 'range.cast_type')),
            'targeting_self' => (bool) data_get($payload, 'targeting.self', false),
            'targeting_party' => (bool) data_get($payload, 'targeting.party', false),
            'targeting_alliance' => (bool) data_get($payload, 'targeting.alliance', false),
            'targeting_hostile' => (bool) data_get($payload, 'targeting.hostile', false),
            'targeting_ally' => (bool) data_get($payload, 'targeting.ally', false),
            'targeting_own_pet' => (bool) data_get($payload, 'targeting.own_pet', false),
            'targeting_party_pet' => (bool) data_get($payload, 'targeting.party_pet', false),
            'targeting_is_area' => (bool) data_get($payload, 'targeting.is_area', false),
            'targeting_dead_target_behavior' => $this->nullableInt(data_get($payload, 'targeting.dead_target_behavior')),
            'targeting_requires_line_of_sight' => (bool) data_get($payload, 'targeting.requires_line_of_sight', false),
            'targeting_requires_facing_target' => (bool) data_get($payload, 'targeting.requires_facing_target', false),
            'combo_previous_action_id' => $this->nullableInt(data_get($payload, 'combo.previous_action_id')),
            'combo_preserves_combo' => (bool) data_get($payload, 'combo.preserves_combo', false),
            'status_gain_self_id' => $this->nullableInt(data_get($payload, 'statuses.gain_self.id')),
            'status_gain_self_name' => $this->nullableString(data_get($payload, 'statuses.gain_self.name')),
            'status_gain_self_description' => $this->nullableString(data_get($payload, 'statuses.gain_self.description')),
            'status_gain_self_icon_id' => $this->nullableInt(data_get($payload, 'statuses.gain_self.icon_id')),
            'status_gain_self_max_stacks' => $this->nullableInt(data_get($payload, 'statuses.gain_self.max_stacks')),
            'status_proc_id' => $this->nullableInt(data_get($payload, 'statuses.proc.id')),
            'status_proc_status_id' => $this->nullableInt(data_get($payload, 'statuses.proc.status.id')),
            'status_proc_status_name' => $this->nullableString(data_get($payload, 'statuses.proc.status.name')),
            'status_proc_status_description' => $this->nullableString(data_get($payload, 'statuses.proc.status.description')),
            'status_proc_status_icon_id' => $this->nullableInt(data_get($payload, 'statuses.proc.status.icon_id')),
            'status_proc_status_max_stacks' => $this->nullableInt(data_get($payload, 'statuses.proc.status.max_stacks')),
            'metadata_aspect_id' => $this->nullableInt(data_get($payload, 'metadata.aspect_id')),
            'metadata_behavior_type' => $this->nullableInt(data_get($payload, 'metadata.behavior_type')),
            'metadata_class_job_category_id' => $this->nullableInt(data_get($payload, 'metadata.class_job_category_id')),
            'metadata_source_class_job_id' => $this->nullableInt(data_get($payload, 'metadata.source_class_job_id')),
            'metadata_is_role_action' => (bool) data_get($payload, 'metadata.is_role_action', false),
            'metadata_is_player_action' => (bool) data_get($payload, 'metadata.is_player_action', false),
            'metadata_is_derived_action' => (bool) data_get($payload, 'metadata.is_derived_action', false),
            'metadata_equivalence_group' => $this->nullableInt(data_get($payload, 'metadata.equivalence_group')),
            'source_payload' => $payload,
            'localized_payloads' => $localizedPayloads,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $localizedPayloads
     * @return array<string, mixed>
     */
    private function mapTraitRecord(array $payload, array $localizedPayloads, string $jsonPath): array
    {
        $sourceId = $this->intValue(data_get($payload, 'id'));
        $role = (string) data_get($payload, 'role', 'Unknown');
        $jobId = $this->intValue(data_get($payload, 'job.id'));

        return [
            'key' => $this->stableKey('trait', $role, $jobId, $sourceId),
            'source_path' => $this->publicRelativePath($jsonPath),
            'source_hash' => $this->sourceHash($jsonPath),
            'source_id' => $sourceId,
            'kind' => (string) data_get($payload, 'kind'),
            'name' => (string) data_get($payload, 'name'),
            'name_translations' => $this->localizedField($localizedPayloads, 'name'),
            'description' => $this->nullableString(data_get($payload, 'description')),
            'description_translations' => $this->localizedField($localizedPayloads, 'description'),
            'description_macro' => $this->nullableString(data_get($payload, 'description_macro')),
            'description_macro_translations' => $this->localizedField($localizedPayloads, 'description_macro'),
            'effects' => $this->arrayValue(data_get($payload, 'effects')),
            'effects_translations' => $this->localizedField($localizedPayloads, 'effects'),
            'role' => $role,
            'job_id' => $jobId,
            'job_name' => (string) data_get($payload, 'job.name'),
            'job_abbreviation' => $this->nullableString(data_get($payload, 'job.abbreviation')),
            'unlock_level' => $this->intValue(data_get($payload, 'unlock_level')),
            'value' => $this->nullableInt(data_get($payload, 'value')),
            'icon_id' => $this->nullableInt(data_get($payload, 'icon.id')),
            'icon_file' => $this->nullableString(data_get($payload, 'icon.file')),
            'icon_url' => $this->iconUrl($jsonPath, $payload),
            'class_job_category_id' => $this->nullableInt(data_get($payload, 'class_job_category_id')),
            'source_class_job_id' => $this->nullableInt(data_get($payload, 'source_class_job_id')),
            'is_phantom_trait' => (bool) data_get($payload, 'is_phantom_trait', false),
            'source_payload' => $payload,
            'localized_payloads' => $localizedPayloads,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $localizedPayloads
     * @return array<string, mixed>
     */
    private function mapBuffRecord(array $payload, array $localizedPayloads, string $jsonPath): array
    {
        $sourceId = $this->intValue(data_get($payload, 'id'));

        return [
            'key' => $this->globalStableKey('combat_status', $sourceId),
            'source_path' => $this->publicRelativePath($jsonPath),
            'source_hash' => $this->sourceHash($jsonPath),
            'source_id' => $sourceId,
            'kind' => (string) data_get($payload, 'kind'),
            'name' => (string) data_get($payload, 'name'),
            'name_translations' => $this->localizedField($localizedPayloads, 'name'),
            'description' => $this->nullableString(data_get($payload, 'description')),
            'description_translations' => $this->localizedField($localizedPayloads, 'description'),
            'effects' => $this->arrayValue(data_get($payload, 'effects')),
            'effects_translations' => $this->localizedField($localizedPayloads, 'effects'),
            'classification' => (string) data_get($payload, 'classification'),
            'icon_id' => $this->nullableInt(data_get($payload, 'icon.id')),
            'icon_file' => $this->nullableString(data_get($payload, 'icon.file')),
            'icon_url' => $this->iconUrl($jsonPath, $payload),
            'max_stacks' => $this->intValue(data_get($payload, 'max_stacks')),
            'status_category_id' => $this->nullableInt(data_get($payload, 'status_category_id')),
            'target_type' => $this->nullableInt(data_get($payload, 'target_type')),
            'can_dispel' => (bool) data_get($payload, 'can_dispel', false),
            'can_remove_manually' => (bool) data_get($payload, 'can_remove_manually', false),
            'is_permanent' => (bool) data_get($payload, 'is_permanent', false),
            'inflicted_by_actor' => (bool) data_get($payload, 'inflicted_by_actor', false),
            'party_list_priority' => $this->intValue(data_get($payload, 'party_list_priority')),
            'parameter_effect' => $this->nullableInt(data_get($payload, 'parameter.effect')),
            'parameter_modifier' => $this->nullableInt(data_get($payload, 'parameter.modifier')),
            'class_job_category_id' => $this->nullableInt(data_get($payload, 'class_job_category_id')),
            'source_abilities' => $this->arrayValue(data_get($payload, 'source_abilities')),
            'source_abilities_translations' => $this->localizedField($localizedPayloads, 'source_abilities'),
            'source_payload' => $payload,
            'localized_payloads' => $localizedPayloads,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $localizedPayloads
     * @return array<string, mixed>
     */
    private function mapPotionRecord(array $payload, array $localizedPayloads, string $jsonPath): array
    {
        $sourceId = $this->intValue(data_get($payload, 'id'));
        $stats = $this->arrayValue(data_get($payload, 'stats'));
        $primaryStat = is_array($stats[0] ?? null) ? $stats[0] : [];

        return [
            'key' => $this->globalStableKey('combat_potion', $sourceId),
            'source_path' => $this->publicRelativePath($jsonPath),
            'source_hash' => $this->sourceHash($jsonPath),
            'source_id' => $sourceId,
            'kind' => (string) data_get($payload, 'kind'),
            'name' => (string) data_get($payload, 'name'),
            'name_translations' => $this->localizedField($localizedPayloads, 'name'),
            'description' => $this->nullableString(data_get($payload, 'description')),
            'description_translations' => $this->localizedField($localizedPayloads, 'description'),
            'description_macro' => $this->nullableString(data_get($payload, 'description_macro')),
            'description_macro_translations' => $this->localizedField($localizedPayloads, 'description_macro'),
            'effects' => $this->arrayValue(data_get($payload, 'effects')),
            'effects_translations' => $this->localizedField($localizedPayloads, 'effects'),
            'icon_id' => $this->nullableInt(data_get($payload, 'icon.id')),
            'icon_file' => $this->nullableString(data_get($payload, 'icon.file')),
            'icon_url' => $this->iconUrl($jsonPath, $payload),
            'item_level' => $this->nullableInt(data_get($payload, 'item_level')),
            'can_be_high_quality' => (bool) data_get($payload, 'can_be_high_quality', false),
            'stack_size' => $this->nullableInt(data_get($payload, 'stack_size')),
            'rarity' => $this->nullableInt(data_get($payload, 'rarity')),
            'category_id' => $this->nullableInt(data_get($payload, 'category.id')),
            'category_name' => $this->nullableString(data_get($payload, 'category.name')),
            'category_translations' => $this->localizedField($localizedPayloads, 'category'),
            'use_item_action_id' => $this->nullableInt(data_get($payload, 'use.item_action_id')),
            'use_action_id' => $this->nullableInt(data_get($payload, 'use.action_id')),
            'use_usable_in_battle' => (bool) data_get($payload, 'use.usable_in_battle', false),
            'use_minimum_level' => $this->nullableInt(data_get($payload, 'use.minimum_level')),
            'use_duration_seconds' => $this->nullableInt(data_get($payload, 'use.duration_seconds')),
            'use_effect_row_id' => $this->nullableInt(data_get($payload, 'use.effect_row_id')),
            'use_raw_data' => $this->arrayValue(data_get($payload, 'use.raw_data')),
            'use_raw_data_high_quality' => $this->arrayValue(data_get($payload, 'use.raw_data_high_quality')),
            'stats' => $stats,
            'stats_translations' => $this->localizedField($localizedPayloads, 'stats'),
            'primary_stat_id' => $this->nullableInt(data_get($primaryStat, 'id')),
            'primary_stat_name' => $this->nullableString(data_get($primaryStat, 'name')),
            'primary_stat_is_percentage' => (bool) data_get($primaryStat, 'is_percentage', false),
            'primary_stat_normal_value' => $this->nullableInt(data_get($primaryStat, 'normal.value')),
            'primary_stat_normal_cap' => $this->nullableInt(data_get($primaryStat, 'normal.cap')),
            'primary_stat_high_quality_value' => $this->nullableInt(data_get($primaryStat, 'high_quality.value')),
            'primary_stat_high_quality_cap' => $this->nullableInt(data_get($primaryStat, 'high_quality.cap')),
            'source_payload' => $payload,
            'localized_payloads' => $localizedPayloads,
            'is_active' => true,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private function readLocalizedPayloads(string $directory, string $expectedKind): array
    {
        $payloads = [];

        foreach (self::LOCALE_FILES as $locale => $filename) {
            $path = $directory.DIRECTORY_SEPARATOR.$filename;

            if (! is_file($path)) {
                continue;
            }

            $payload = $this->readJsonFile($path);
            $kind = (string) ($payload['kind'] ?? '');

            if ($kind !== $expectedKind) {
                throw new RuntimeException("Localized calculator data kind mismatch in {$path}");
            }

            $payloads[$locale] = $payload;
        }

        return $payloads;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private function readJsonFile(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read calculator data file: {$path}");
        }

        $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($payload)) {
            throw new RuntimeException("Calculator data file must contain an object: {$path}");
        }

        return $payload;
    }

    private function stableKey(string $kind, string $role, int $jobId, int $sourceId): string
    {
        return implode(':', [
            $kind,
            Str::slug($role),
            $jobId,
            $sourceId,
        ]);
    }

    private function globalStableKey(string $kind, int $sourceId): string
    {
        return "{$kind}:{$sourceId}";
    }

    private function iconUrl(string $jsonPath, array $payload): ?string
    {
        $iconFile = $this->nullableString(data_get($payload, 'icon.file'));

        if ($iconFile === null) {
            return null;
        }

        $relativePath = $this->publicRelativePath(dirname($jsonPath).DIRECTORY_SEPARATOR.$iconFile);

        return '/'.collect(explode('/', $relativePath))
            ->map(fn (string $segment) => rawurlencode($segment))
            ->implode('/');
    }

    private function publicRelativePath(string $path): string
    {
        $publicPath = realpath(public_path()) ?: public_path();
        $realPath = realpath($path) ?: $path;
        $prefix = rtrim($publicPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (str_starts_with($realPath, $prefix)) {
            $realPath = substr($realPath, strlen($prefix));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $realPath);
    }

    private function sourceHash(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException("Unable to hash calculator data file: {$path}");
        }

        return $hash;
    }

    private function resolveSourceDirectory(?string $sourceDirectory): string
    {
        if ($sourceDirectory === null || $sourceDirectory === '') {
            return public_path('CalculatorData');
        }

        if (is_dir($sourceDirectory)) {
            return $sourceDirectory;
        }

        return base_path($sourceDirectory);
    }

    /**
     * @param  array<string, array<string, mixed>>  $localizedPayloads
     * @return array<string, mixed>
     */
    private function localizedField(array $localizedPayloads, string $field): array
    {
        $values = [];

        foreach ($localizedPayloads as $locale => $payload) {
            if (array_key_exists($field, $payload)) {
                $values[$locale] = $payload[$field];
            }
        }

        return $values;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function intValue(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new RuntimeException('Calculator data expected a numeric value.');
        }

        return (int) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : $this->intValue($value);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
