<?php

namespace App\Services\Groups;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

final class FtelLegacyLeaderboardService
{
    private const EXPORT_FILENAME = 'players_export.json';

    private const RAID_LEADER_BADGES = [
        ['key' => 'legendary', 'threshold' => 50, 'icon' => 'i-lucide-crown'],
        ['key' => 'diamond', 'threshold' => 40, 'icon' => 'i-lucide-gem'],
        ['key' => 'platinum', 'threshold' => 30, 'icon' => 'i-lucide-trophy'],
        ['key' => 'gold', 'threshold' => 20, 'icon' => 'i-lucide-medal'],
        ['key' => 'silver', 'threshold' => 10, 'icon' => 'i-lucide-medal'],
        ['key' => 'bronze', 'threshold' => 1, 'icon' => 'i-lucide-medal'],
    ];

    private const PARTICIPATION_BADGES = [
        ['key' => 'elite', 'threshold' => 25, 'icon' => 'i-lucide-crown'],
        ['key' => 'veteran', 'threshold' => 10, 'icon' => 'i-lucide-star'],
        ['key' => 'active', 'threshold' => 1, 'icon' => 'i-lucide-circle'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $records = $this->records();
        $participations = $this->ranking($records, 'participation_count');
        $raidLeaders = $this->ranking($records, 'raid_leader_count');

        return [
            'source' => [
                'label' => 'FTEL legacy website export',
                'is_static' => true,
            ],
            'summary' => [
                'total_players' => $records->count(),
                'ranked_participants' => count($participations),
                'ranked_raid_leaders' => count($raidLeaders),
                'total_participations' => $records->sum('participation_count'),
                'total_raid_leader_participations' => $records->sum('raid_leader_count'),
            ],
            'rankings' => [
                'participations' => $participations,
                'raid_leaders' => $raidLeaders,
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function records(): Collection
    {
        $path = public_path(self::EXPORT_FILENAME);

        if (! File::exists($path)) {
            return collect();
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return collect();
        }

        return collect($decoded)
            ->filter(fn (mixed $record) => is_array($record))
            ->map(fn (array $record) => $this->normalizeRecord($record))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function ranking(Collection $records, string $countKey): array
    {
        return $records
            ->filter(fn (array $record) => (int) $record[$countKey] > 0)
            ->sort(function (array $left, array $right) use ($countKey): int {
                $countComparison = (int) $right[$countKey] <=> (int) $left[$countKey];

                if ($countComparison !== 0) {
                    return $countComparison;
                }

                return strcmp((string) $left['character']['name'], (string) $right['character']['name']);
            })
            ->values()
            ->map(fn (array $record, int $index) => [
                'rank' => $index + 1,
                'character' => $record['character'],
                'participation_count' => $record['participation_count'],
                'raid_leader_count' => $record['raid_leader_count'],
                'rescue_count' => $record['rescue_count'],
                'assignment_count' => $record['assignment_count'],
                'badges' => $record['badges'],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalizeRecord(array $record): array
    {
        $normalized = [
            'character' => [
                'id' => isset($record['character_id']) ? (int) $record['character_id'] : null,
                'name' => filled($record['name'] ?? null) ? trim((string) $record['name']) : __('ui.unknown_character'),
                'world' => filled($record['world'] ?? null) ? trim((string) $record['world']) : null,
                'datacenter' => filled($record['data_center'] ?? null) ? trim((string) $record['data_center']) : null,
                'avatar_url' => filled($record['avatar_url'] ?? null) ? trim((string) $record['avatar_url']) : null,
            ],
            'participation_count' => max(0, (int) ($record['participation_count'] ?? 0)),
            'raid_leader_count' => max(0, (int) ($record['raid_leader_count'] ?? 0)),
            'rescue_count' => max(0, (int) ($record['rescue_count'] ?? 0)),
            'assignment_count' => max(0, (int) ($record['assignment_count'] ?? 0)),
        ];

        $normalized['badges'] = $this->badgesFor(
            $normalized['participation_count'],
            $normalized['raid_leader_count'],
        );

        return $normalized;
    }

    /**
     * @return array<int, array{type: string, key: string, icon: string}>
     */
    private function badgesFor(int $participationCount, int $raidLeaderCount): array
    {
        return collect([
            $this->participationBadge($participationCount),
            $this->raidLeaderBadge($raidLeaderCount),
        ])
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{type: string, key: string, icon: string}|null
     */
    private function participationBadge(int $count): ?array
    {
        return $this->highestBadge('participation', self::PARTICIPATION_BADGES, $count);
    }

    /**
     * @return array{type: string, key: string, icon: string}|null
     */
    private function raidLeaderBadge(int $count): ?array
    {
        return $this->highestBadge('leader', self::RAID_LEADER_BADGES, $count);
    }

    /**
     * @param  array<int, array{key: string, threshold: int, icon: string}>  $tiers
     * @return array{type: string, key: string, icon: string}|null
     */
    private function highestBadge(string $type, array $tiers, int $count): ?array
    {
        foreach ($tiers as $tier) {
            if ($count >= $tier['threshold']) {
                return [
                    'type' => $type,
                    'key' => $tier['key'],
                    'icon' => $tier['icon'],
                ];
            }
        }

        return null;
    }
}
