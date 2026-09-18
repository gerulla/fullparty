<?php

namespace App\Services\Groups\Resources;

use App\Support\XivGearSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class XivGearImportService
{
    public function __construct(private readonly XivGearItemData $items) {}

    public function import(string $url, ?array $setIndices = null): array
    {
        if (! XivGearSnapshot::validUrl($url)) {
            throw ValidationException::withMessages(['url' => __('xivgear.invalid_url')]);
        }
        try {
            $sheet = Cache::remember('xivgear:sheet:v1:'.hash('sha256', $url), now()->addMinutes(15), function () use ($url) {
                $response = Http::acceptJson()->timeout(30)->connectTimeout(5)->withoutRedirecting()->get('https://api.xivgear.app/fulldata', ['url' => $url]);
                if (! $response->successful() || strlen($response->body()) > 5000000) {
                    throw ValidationException::withMessages(['url' => __('xivgear.import_failed')]);
                }
                $data = $response->json();
                if (! is_array($data) || ! is_array($data['sets'] ?? null) || count($data['sets']) > 100) {
                    throw ValidationException::withMessages(['url' => __('xivgear.unsupported')]);
                }
                $data['importedAt'] = now()->toIso8601String();

                return $data;
            });
            $sets = [];
            foreach ($sheet['sets'] as $index => $set) {
                if (is_array($set) && empty($set['isSeparator']) && ! empty($set['items'])) {
                    $sets[] = ['index' => $index, 'name' => mb_substr((string) ($set['name'] ?? $sheet['name'] ?? 'Gearset'), 0, 500), 'job' => $set['computedStats']['job'] ?? $sheet['job'] ?? ''];
                }
            }
            if (! $sets || ($setIndices !== null && (! $setIndices || array_diff($setIndices, array_column($sets, 'index'))))) {
                throw ValidationException::withMessages(['url' => __('xivgear.unsupported')]);
            }
            if ($setIndices === null && count($sets) > 1) {
                return ['sets' => $sets, 'snapshots' => []];
            }
            $snapshots = [];
            $selected = array_map(fn ($index) => $sheet['sets'][$index], $setIndices ?? [$sets[0]['index']]);
            $metadata = $this->items->items(array_merge(...array_map($this->itemIds(...), $selected)));
            foreach ($selected as $set) {
                $snapshot = $this->snapshot($url, $sheet, $set, $metadata);
                if (! XivGearSnapshot::valid($snapshot)) {
                    throw ValidationException::withMessages(['url' => __('xivgear.unsupported')]);
                }
                $snapshots[] = $snapshot;
            }

            return ['sets' => $sets, 'snapshots' => $snapshots];
        } catch (ConnectionException|RequestException $exception) {
            throw ValidationException::withMessages(['url' => __('xivgear.import_failed')]);
        }
    }

    private function itemIds(array $set): array
    {
        if (! is_array($set['items'] ?? null) || count($set['items']) > 12) {
            throw ValidationException::withMessages(['url' => __('xivgear.unsupported')]);
        }
        $equipped = array_intersect_key($set['items'], array_flip(XivGearSnapshot::SLOTS));
        $ids = [];
        foreach ($equipped as $item) {
            if (! is_array($item) || ! is_array($item['materia'] ?? []) || count($item['materia'] ?? []) > 5 || ! is_array($item['relicStats'] ?? [])) {
                throw ValidationException::withMessages(['url' => __('xivgear.unsupported')]);
            }
            $ids[] = $item['id'] ?? 0;
            foreach ($item['materia'] ?? [] as $materia) {
                if (($materia['id'] ?? -1) > 0) {
                    $ids[] = $materia['id'];
                }
            }
        }
        if (! empty($set['food'])) {
            $ids[] = $set['food'];
        }

        return $ids;
    }

    private function snapshot(string $url, array $sheet, array $set, array $metadata): array
    {
        $equipped = array_intersect_key($set['items'], array_flip(XivGearSnapshot::SLOTS));
        $items = [];
        $totalLevel = 0;
        foreach (XivGearSnapshot::SLOTS as $slot) {
            if (! isset($equipped[$slot])) {
                continue;
            }
            $item = $equipped[$slot];
            $base = $metadata[$item['id']];
            $materia = [];
            foreach ($item['materia'] ?? [] as $meld) {
                if (($meld['id'] ?? -1) > 0) {
                    $materia[] = $metadata[$meld['id']];
                }
            }
            $items[] = $base + ['slot' => $slot, 'materia' => $materia, 'relicStats' => array_intersect_key($item['relicStats'] ?? [], array_flip(XivGearSnapshot::STATS))];
            $totalLevel += $base['itemLevel'];
        }
        $stats = $set['computedStats'] ?? [];
        $job = $stats['job'] ?? $sheet['job'] ?? '';
        if ($job !== 'PLD' && isset($equipped['Weapon']) && ! isset($equipped['OffHand'])) {
            $totalLevel += $metadata[$equipped['Weapon']['id']]['itemLevel'];
        }
        $speed = in_array($stats['jobStats']['combatRole'] ?? '', ['Healer', 'Caster'], true) ? ($stats['spellspeed'] ?? null) : ($stats['skillspeed'] ?? null);
        $levelStats = $stats['levelStats'] ?? [];
        $gcd = null;
        if (is_numeric($speed) && ($levelStats['levelDiv'] ?? 0) > 0 && isset($levelStats['baseSubStat'])) {
            // XIVGear's standard 2.5s recast formula, before job/ability haste.
            $gcd = floor(floor((1000 - floor(130 * ($speed - $levelStats['baseSubStat']) / $levelStats['levelDiv'])) * 2.5) / 10) / 100;
        }

        return [
            'version' => 1, 'sourceUrl' => $url, 'importedAt' => $sheet['importedAt'],
            'name' => mb_substr((string) ($set['name'] ?? $sheet['name'] ?? 'Gearset'), 0, 500),
            'description' => mb_substr((string) ($set['description'] ?? ''), 0, 5000),
            'job' => $job, 'level' => (int) ($stats['level'] ?? $sheet['level'] ?? 0),
            'partyBonus' => (int) ($sheet['partyBonus'] ?? 0), 'itemLevel' => (int) floor($totalLevel / 12),
            'itemLevelSync' => $sheet['ilvlSync'] ?? null, 'gcd' => $gcd,
            'stats' => array_map(fn ($value) => (int) $value, array_intersect_key($stats, array_flip(XivGearSnapshot::STATS))),
            'items' => $items, 'food' => empty($set['food']) ? null : $metadata[$set['food']],
        ];
    }
}
