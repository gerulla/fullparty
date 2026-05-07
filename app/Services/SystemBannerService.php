<?php

namespace App\Services;

use App\Models\SystemBanner;

class SystemBannerService
{
    public function current(): ?SystemBanner
    {
        return SystemBanner::query()->latest('updated_at')->latest('id')->first();
    }

    /**
     * @param array{title:string,message:string,action_label:?string,action_url:?string} $attributes
     */
    public function upsert(array $attributes): SystemBanner
    {
        $banner = $this->current() ?? new SystemBanner();

        $banner->fill([
            'title' => $attributes['title'],
            'message' => $attributes['message'],
            'action_label' => filled($attributes['action_label'] ?? null) ? $attributes['action_label'] : null,
            'action_url' => filled($attributes['action_url'] ?? null) ? $attributes['action_url'] : null,
        ])->save();

        return $banner->fresh();
    }

    public function clear(): void
    {
        SystemBanner::query()->delete();
    }

    public function serialize(?SystemBanner $banner = null): ?array
    {
        $banner ??= $this->current();

        if ($banner === null) {
            return null;
        }

        return [
            'id' => $banner->id,
            'title' => $banner->title,
            'message' => $banner->message,
            'action_label' => $banner->action_label,
            'action_url' => $banner->action_url,
            'updated_at' => $banner->updated_at?->toIso8601String(),
        ];
    }
}
