<?php

namespace App\Http\Resources\XivPlugin;

use App\Models\ActivityApplication;
use App\Models\ActivityApplicationAnswer;
use App\Models\ActivitySlot;
use App\Models\Character;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class XivPluginRunApplicationResource extends JsonResource
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        $resource,
        private readonly ?ActivitySlot $slot = null,
        private readonly ?array $details = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ActivityApplication $application */
        $application = $this->resource;

        return [
            'id' => $application->id,
            'activity_id' => $application->activity_id,
            'status' => $application->status,
            'notes' => $application->notes,
            'review_reason' => $application->review_reason,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'reviewed_at' => $application->reviewed_at?->toIso8601String(),
            'user' => $application->user ? [
                'id' => $application->user->id,
                'name' => $application->user->name,
                'avatar_url' => $this->imageUrl($application->user->avatar_url),
            ] : null,
            'selected_character' => $this->character($application->selectedCharacter),
            'applicant_character' => [
                'lodestone_id' => $application->applicant_lodestone_id,
                'name' => $application->applicant_character_name,
                'world' => $application->applicant_world,
                'datacenter' => $application->applicant_datacenter,
                'avatar_url' => $this->imageUrl($application->applicant_avatar_url),
            ],
            'slot' => $this->slot ? [
                'id' => $this->slot->id,
                'group_key' => $this->slot->group_key,
                'group_label' => $this->slot->group_label ?? [],
                'slot_key' => $this->slot->slot_key,
                'slot_label' => $this->slot->slot_label ?? [],
                'position_in_group' => $this->slot->position_in_group,
            ] : null,
            'answers' => $application->answers
                ->map(fn (ActivityApplicationAnswer $answer): array => [
                    'question_key' => $answer->question_key,
                    'question_label' => $answer->question_label ?? [],
                    'question_type' => $answer->question_type,
                    'source' => $answer->source,
                    'value' => $answer->value,
                ])
                ->values()
                ->all(),
            'details' => $this->withAbsoluteUrls($this->details),
        ];
    }

    private function withAbsoluteUrls(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (! is_array($value)) {
            return $value;
        }

        return collect($value)
            ->mapWithKeys(function (mixed $item, int|string $key): array {
                if (is_string($key) && str_ends_with($key, '_url') && (is_string($item) || $item === null)) {
                    return [$key => $this->imageUrl($item)];
                }

                return [$key => $this->withAbsoluteUrls($item)];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function character(?Character $character): ?array
    {
        if (! $character) {
            return null;
        }

        return [
            'id' => $character->id,
            'name' => $character->name,
            'world' => $character->world,
            'datacenter' => $character->datacenter,
            'avatar_url' => $this->imageUrl($character->avatar_url),
            'user_id' => $character->user_id,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }
}
