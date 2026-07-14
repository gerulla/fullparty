<?php

namespace App\Console\Commands;

use App\Models\BozjaItem;
use App\Services\ManagedImageStorage;
use App\Support\Bozja\BozjaItemCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class ImportBozjaData extends Command
{
    protected $signature = 'bozja:import';

    protected $description = 'Import the tracked Bozja reference data into system data';

    public function handle(ManagedImageStorage $managedImageStorage): int
    {
        $sourceDirectory = public_path('BozjaInfo');

        if (! is_dir($sourceDirectory)) {
            $this->error("Bozja data directory not found: {$sourceDirectory}");

            return self::FAILURE;
        }

        try {
            $records = $this->readRecords($sourceDirectory);
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        DB::transaction(function () use ($records, $managedImageStorage): void {
            foreach ($records as $record) {
                $record['icon_url'] = $managedImageStorage->storeLocalImageAsWebp(
                    $record['source_icon_path'],
                    'bozja-items/'.$record['key'].'.webp',
                );
                unset($record['source_icon_path']);

                BozjaItem::query()->updateOrCreate(
                    ['key' => $record['key']],
                    $record,
                );
            }
        });

        $this->info(sprintf('Imported %d Bozja items.', count($records)));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private function readRecords(string $sourceDirectory): array
    {
        $directories = glob($sourceDirectory.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        sort($directories, SORT_NATURAL | SORT_FLAG_CASE);

        if ($directories === []) {
            throw new RuntimeException('No Bozja item directories were found.');
        }

        return collect($directories)
            ->map(fn (string $directory, int $index) => $this->readRecord($directory, $index))
            ->all();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private function readRecord(string $directory, int $index): array
    {
        $jsonPath = $directory.DIRECTORY_SEPARATOR.'info.json';
        $iconPath = $directory.DIRECTORY_SEPARATOR.'icon.png';

        if (! is_file($jsonPath) || ! is_file($iconPath)) {
            throw new RuntimeException('Each Bozja item directory must contain info.json and icon.png: '.basename($directory));
        }

        $contents = file_get_contents($jsonPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read Bozja data: '.$jsonPath);
        }

        $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $english = $payload['en'] ?? null;

        if (! is_array($english) || blank($english['title'] ?? null) || blank($english['classification'] ?? null)) {
            throw new RuntimeException('Bozja data requires an English title and classification: '.basename($directory));
        }

        $classification = (string) $english['classification'];
        $category = BozjaItemCategory::categoryForClassification($classification);

        if ($category === null) {
            throw new RuntimeException("Unsupported Bozja classification [{$classification}] in ".basename($directory));
        }

        $name = [];
        $description = [];

        foreach (['en', 'de', 'fr', 'ja'] as $locale) {
            $localized = $payload[$locale] ?? null;

            if (! is_array($localized) || blank($localized['title'] ?? null)) {
                throw new RuntimeException("Missing {$locale} Bozja title in ".basename($directory));
            }

            $name[$locale] = (string) $localized['title'];
            $description[$locale] = filled($localized['description'] ?? null)
                ? (string) $localized['description']
                : null;
        }

        return [
            'key' => Str::slug((string) $english['title']),
            'category' => $category,
            'name' => $name,
            'description' => $description,
            'classification' => $classification,
            'cache_weight' => max(0, (int) data_get($english, 'cache.weight', 0)),
            'source_icon_path' => $iconPath,
            'source_payload' => $payload,
            'sort_order' => max(0, (int) data_get($english, 'cache.order', $index)),
            'is_active' => true,
        ];
    }
}
