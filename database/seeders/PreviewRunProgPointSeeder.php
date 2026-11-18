<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityTypeVersion;
use Illuminate\Database\Seeder;

class PreviewRunProgPointSeeder extends Seeder
{
    public static function targetKey(ActivityTypeVersion $version, string $marker): ?string
    {
        $keys = array_values(array_filter(
            array_column($version->prog_points ?? [], 'key'),
            fn ($key) => is_string($key) && filled($key),
        ));

        // A stable draw per fixture keeps consecutive runs varied across seed passes.
        return $keys === [] ? null : $keys[crc32($marker) % count($keys)];
    }

    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403);

        $updated = 0;
        $runs = Activity::query()
            ->select(['id', 'settings', 'activity_type_version_id', 'target_prog_point_key'])
            ->where(function ($query) {
                $query->where('settings->preview_seed', 'like', 'calendar-preview:%')
                    ->orWhere('settings->preview_seed', 'like', 'homepage-preview:%');
            })
            ->with('activityTypeVersion:id,prog_points')
            ->lazyById(250);

        foreach ($runs as $run) {
            $version = $run->activityTypeVersion;
            $current = $run->target_prog_point_key;
            // Only replace the old factory default. Keep deliberately customized targets.
            if ($current !== null && $current !== ($version->prog_points[0]['key'] ?? null)) {
                continue;
            }
            $target = self::targetKey($version, $run->settings['preview_seed']);
            if ($target !== $current) {
                $updated += Activity::whereKey($run->id)
                    ->where('activity_type_version_id', $version->id)
                    ->where('target_prog_point_key', $current)
                    ->toBase()->update(['target_prog_point_key' => $target]);
            }
        }

        $this->command?->info("Updated prog points on {$updated} preview runs.");
    }
}
