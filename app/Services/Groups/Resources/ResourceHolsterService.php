<?php

namespace App\Services\Groups\Resources;

use App\Models\BozjaHolster;
use App\Models\Group;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceLibrary;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use App\Services\RichText\MarkdownGuideConverter;
use App\Services\RichText\RichTextDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResourceHolsterService
{
    public function __construct(
        private readonly ResourceLibraryService $libraries,
        private readonly GroupResourcePolicy $policy,
        private readonly ResourceAudit $audit,
        private readonly RichTextDocument $documents,
        private readonly MarkdownGuideConverter $markdown,
    ) {}

    public function collectionId(Group $group): ?int
    {
        $id = GroupResourceLibrary::where('group_id', $group->id)->value('holster_collection_id');

        return $id && GroupResourceCollection::where('group_id', $group->id)->whereKey($id)->exists() ? (int) $id : null;
    }

    public function configure(Group $group, User $user, array $input): array
    {
        abort_unless($this->policy->manageLibrary($user, $group), 403);

        DB::transaction(function () use ($group, $user, $input) {
            $library = $this->libraries->lock($group);
            $data = Validator::make($input, [
                'collection_id' => ['present', 'nullable', 'integer', Rule::exists('group_resource_collections', 'id')->where('group_id', $group->id)],
            ])->validate();
            $library->update(['holster_collection_id' => $data['collection_id']]);
            $this->audit->record($group, $user, $library, 'settings_updated');
        });

        return $this->management($group);
    }

    /** Live listings use the original holsters; no resource copies or publication history are created. */
    public function query(Group $group, array $filters = []): Builder
    {
        $query = BozjaHolster::where('group_id', $group->id)->where('is_active', true);
        if ($this->collectionId($group) === null || ! empty($filters['activity_type_id'])) {
            return $query->whereRaw('1 = 0');
        }
        if (($text = trim($filters['q'] ?? '')) !== '') {
            $pattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($text)).'%';
            $query->where(function (Builder $search) use ($pattern, $text) {
                foreach (['en', 'de', 'fr', 'ja'] as $locale) {
                    $search->orWhereLike('name->'.$locale, $pattern);
                }
                $search->orWhereLike('notes', $pattern)->orWhereLike('guide', $pattern)
                    ->orWhereLike('role', $pattern)->orWhereLike('type', $pattern);
                if (in_array(mb_strtolower(ltrim($text, '#')), ['drs', 'holster', 'holsters'], true)) {
                    $search->orWhereRaw('1 = 1');
                }
            });
        }
        if ($tag = $filters['tag'] ?? null) {
            if (! in_array(mb_strtolower($tag), ['drs', 'holster'], true)) {
                $query->where(fn ($tags) => $tags->where('role', mb_strtolower($tag))->orWhere('type', mb_strtolower($tag)));
            }
        }

        return $query;
    }

    public function summaries(Builder $query, int $collectionId): array
    {
        return $query->get(['id', 'name', 'notes', 'role', 'type', 'updated_at'])
            ->map(fn (BozjaHolster $holster) => $this->summary($holster, $collectionId))->all();
    }

    public function summary(BozjaHolster $holster, int $collectionId): array
    {
        return [
            'id' => -$holster->id, 'source_type' => 'holster', 'holster_id' => $holster->id,
            'slug' => (string) $holster->id, 'collection_id' => $collectionId, 'is_home' => false,
            'title' => $holster->localizedName() ?? __('resource_library.untitled_holster'),
            'description' => $holster->notes ?? '',
            'tags' => array_values(array_filter(['drs', 'holster', $holster->role, $holster->type])),
            'activity_type_ids' => [], 'metadata_image_id' => null, 'author' => null,
            'access_level' => 'everyone', 'published_at' => $holster->updated_at?->toIso8601String(),
        ];
    }

    public function detail(Group $group, BozjaHolster $holster, ?User $user, bool $public): array
    {
        abort_unless($public ? $this->libraries->isPublic($group) : $this->policy->library($user, $group), $public ? 404 : 403);
        $collectionId = $this->collectionId($group);
        abort_unless($collectionId !== null && (int) $holster->group_id === (int) $group->id && $holster->is_active, 404);
        $body = is_array($holster->guide) ? $holster->guide : RichTextDocument::empty();
        $holster->loadMissing(['items' => fn ($items) => $items->orderBy('bozja_items.sort_order')->orderBy('bozja_items.key')]);
        $prepop = $holster->type === BozjaHolster::TYPE_PREPOP ? $holster : $holster->parentHolster()
            ->where('group_id', $group->id)->where('is_active', true)->where('type', BozjaHolster::TYPE_PREPOP)
            ->with('items')->first();
        $refills = $holster->type === BozjaHolster::TYPE_REFILL ? collect([$holster]) : $holster->refillHolsters()
            ->where('group_id', $group->id)->where('is_active', true)->where('type', BozjaHolster::TYPE_REFILL)
            ->with('items')->orderByDesc('is_default')->orderBy('id')->get();

        return $this->summary($holster, $collectionId) + [
            'body' => $body,
            'legacy_body_html' => is_string($holster->guide) ? $this->markdown->legacyHtml($holster->guide) : null,
            'body_html' => is_array($holster->guide) ? $this->documents->html($body) : $this->markdown->legacyHtml($holster->guide ?? ''),
            'images' => [], 'linked_resources' => [], 'commands' => [],
            'history' => ['data' => [], 'has_more' => false],
            'holster' => $this->loadout($holster) + [
                'prepop' => $prepop ? $this->loadout($prepop) : null,
                'refills' => $refills->map(fn (BozjaHolster $refill) => $this->loadout($refill))->all(),
            ],
        ];
    }

    private function loadout(BozjaHolster $holster): array
    {
        return [
            'id' => $holster->id,
            'name' => $holster->localizedName() ?? __('resource_library.untitled_holster'),
            'role' => $holster->role, 'type' => $holster->type, 'notes' => $holster->notes,
            'capacity_used' => $holster->capacity_used, 'max_capacity' => $holster->max_capacity,
            'items' => $holster->items->sortBy([['sort_order', 'asc'], ['key', 'asc']])->map(fn ($item) => [
                'id' => $item->id, 'name' => $item->localizedName(), 'icon_url' => $item->icon_url,
                'quantity' => (int) $item->pivot->quantity, 'cache_weight' => $item->cache_weight,
            ])->values()->all(),
        ];
    }

    public function management(Group $group): array
    {
        $collectionId = $this->collectionId($group);

        return [
            'collection_id' => $collectionId,
            'active_count' => BozjaHolster::where('group_id', $group->id)->where('is_active', true)->count(),
            'resources' => $collectionId ? $this->summaries($this->query($group)->orderByDesc('is_default')->orderBy('id'), $collectionId) : [],
        ];
    }
}
