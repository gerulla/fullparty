<?php

namespace App\Http\Resources\Groups;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ResourceReaderHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $editor = is_string($this->editor) ? json_decode($this->editor, true) : ($this->editor ?? []);
        $publication = (bool) $this->publication;

        return [
            'id' => $publication ? 'publication-'.$this->id : (int) $this->id,
            'kind' => $publication ? 'publication' : 'edit',
            'editor' => array_intersect_key($editor, array_flip(['name', 'avatar_url'])),
            'summary' => $publication ? __('resource_history.published', ['user' => $editor['name'] ?? '']) : $this->summary,
            'created_at' => $this->created_at ? Carbon::parse($this->created_at)->toIso8601String() : null,
        ];
    }
}
