<?php

namespace App\Services\Groups\Resources;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class XivGearItemData
{
    public function items(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (count($ids) > 400 || array_filter($ids, fn ($id) => ! is_int($id) || $id < 1 || $id > 1000000)) {
            throw ValidationException::withMessages(['url' => __('xivgear.unsupported')]);
        }
        $items = [];
        $missing = [];
        foreach ($ids as $id) {
            $cached = Cache::get('xivgear:item:v1:'.$id);
            if ($cached) {
                $items[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }
        foreach (array_chunk($missing, 100) as $batch) {
            $response = Http::acceptJson()->timeout(20)->connectTimeout(5)->withoutRedirecting()->get('https://v2.xivapi.com/api/sheet/Item', [
                'rows' => implode(',', $batch), 'fields' => 'Name,Name@lang(de),Name@lang(fr),Name@lang(ja),Icon,LevelItem@as(raw)',
            ])->throw();
            if (strlen($response->body()) > 1000000) {
                throw ValidationException::withMessages(['url' => __('xivgear.import_failed')]);
            }
            foreach ($response->json('rows', []) as $row) {
                $id = $row['row_id'] ?? 0;
                $fields = $row['fields'] ?? [];
                if (! in_array($id, $batch, true) || ! is_string($fields['Name'] ?? null) || $fields['Name'] === '') {
                    continue;
                }
                $names = ['en' => $fields['Name']];
                foreach (['de', 'fr', 'ja'] as $locale) {
                    $names[$locale] = $fields['Name@lang('.$locale.')'] ?? $fields['Name'];
                }
                $items[$id] = ['id' => $id, 'names' => $names, 'icon' => (int) ($fields['Icon']['id'] ?? 0) ?: null, 'itemLevel' => (int) ($fields['LevelItem@as(raw)'] ?? 0)];
                Cache::put('xivgear:item:v1:'.$id, $items[$id], now()->addDays(30));
            }
        }
        if (count($items) !== count($ids)) {
            throw ValidationException::withMessages(['url' => __('xivgear.unsupported')]);
        }
        $this->cacheIcons(array_values(array_unique(array_filter(array_column($items, 'icon')))));

        return $items;
    }

    private function cacheIcons(array $icons): void
    {
        $disk = Storage::disk('local');
        $missing = array_filter($icons, fn ($id) => $id > 0 && $id <= 999999 && ! $disk->exists('xivgear-icons/'.$id.'.png') && ! Cache::has('xivgear:icon-failed:'.$id));
        foreach (array_chunk($missing, 10) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk) {
                return array_map(function ($id) use ($pool) {
                    $path = sprintf('ui/icon/%06d/%06d.tex', intdiv($id, 1000) * 1000, $id);

                    return $pool->as((string) $id)->timeout(5)->connectTimeout(3)->withoutRedirecting()->get('https://v2.xivapi.com/api/asset', ['path' => $path, 'format' => 'png']);
                }, $chunk);
            });
            foreach ($chunk as $id) {
                $response = $responses[(string) $id] ?? null;
                if ($response instanceof Response && $response->successful() && strlen($response->body()) <= 100000 && str_starts_with($response->body(), "\x89PNG\r\n\x1a\n")) {
                    $disk->put('xivgear-icons/'.$id.'.png', $response->body());
                } else {
                    Cache::put('xivgear:icon-failed:'.$id, true, now()->addHour());
                }
            }
        }
    }
}
