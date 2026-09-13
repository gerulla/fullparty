<?php

namespace App\Services\Groups\Resources;

use App\Http\Resources\Groups\ResourceSummaryResource;
use App\Models\BozjaHolster;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceLibrary;
use App\Models\GroupResourceTag;
use App\Models\User;
use App\Policies\GroupResourcePolicy;
use App\Services\RichText\MarkdownGuideConverter;
use App\Services\RichText\RichTextDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
            if ($library->holster_collection_id !== $data['collection_id']) {
                $linked = GroupResource::where('group_id', $group->id)->whereNotNull('holster_id');
                abort_if((clone $linked)->where('editing_expires_at', '>', now())->exists(), 409, __('resource_errors.locked'));
                $linked->update(['collection_id' => $data['collection_id'], 'version' => DB::raw('version + 1')]);
            }
            $library->update(['holster_collection_id' => $data['collection_id']]);
            $this->audit->record($group, $user, $library, 'settings_updated');
        });

        return $this->management($group, $user);
    }

    /** Create missing metadata records, including libraries configured before this feature. */
    public function synchronize(Group $group): void
    {
        if ($this->collectionId($group) === null) {
            return;
        }
        $missing = fn () => BozjaHolster::where('group_id', $group->id)->where('is_active', true)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('group_resources')->whereColumn('holster_id', 'bozja_holsters.id'));
        if (! $missing()->exists()) {
            return;
        }
        DB::transaction(function () use ($group, $missing) {
            $library = $this->libraries->lock($group);
            if (! $library->holster_collection_id) {
                return;
            }
            foreach ($missing()->orderBy('id')->get() as $holster) {
                $resource = GroupResource::create([
                    'group_id' => $group->id, 'holster_id' => $holster->id,
                    'collection_id' => $library->holster_collection_id,
                    'slug' => 'holster-'.Str::uuid(), 'status' => 'published',
                    'access_level' => 'everyone', 'management_access_level' => 'everyone',
                    'published_at' => $holster->updated_at,
                ]);
                $resource->setRelation('holster', $holster);
                $snapshot = app(ResourceHolsterContent::class)->inherit($resource, [
                    'slug' => $resource->slug, 'access_level' => 'everyone',
                    'tags' => array_values(array_filter(['drs', 'holster', $holster->role, $holster->type])),
                    'commands' => [], 'metadata_image_id' => null, 'image_ids' => [],
                    'author' => null, 'body_format' => RichTextDocument::FORMAT,
                ]);
                $revision = $resource->revisions()->create([
                    'snapshot' => $snapshot, 'editor' => ['name' => 'FullParty'],
                    'summary' => __('resource_library.holster_linked'), 'state' => 'published', 'published_at' => now(),
                ]);
                $resource->update(['working_copy' => $snapshot, 'published_revision_id' => $revision->id]);
                $resource->activityTypes()->sync($snapshot['activity_type_ids']);
                $resource->tags()->sync(array_map(fn ($name) => GroupResourceTag::firstOrCreate(['group_id' => $group->id, 'name' => $name])->id, $snapshot['tags']));
                DB::table('group_resource_slugs')->insert(['group_id' => $group->id, 'resource_id' => $resource->id, 'slug' => $resource->slug]);
            }
        });
    }

    public function presentation(GroupResource $resource, ?User $user, bool $public): array
    {
        $group = $resource->group;
        $holster = $resource->holster;
        $levels = $public ? ['everyone'] : $this->policy->levels($user, $group);
        $visibleIds = GroupResource::where('group_id', $group->id)->withAvailableSource()
            ->where('status', 'published')->whereNotNull('published_revision_id')->whereIn('access_level', $levels)->select('holster_id');
        $body = is_array($holster->guide) ? $holster->guide : RichTextDocument::empty();
        $holster->loadMissing(['items' => fn ($items) => $items->orderBy('bozja_items.sort_order')->orderBy('bozja_items.key')]);
        $prepop = $holster->type === BozjaHolster::TYPE_PREPOP ? $holster : $holster->parentHolster()
            ->where('group_id', $group->id)->whereIn('id', $visibleIds)->where('type', BozjaHolster::TYPE_PREPOP)
            ->with('items')->first();
        $refills = $prepop ? $prepop->refillHolsters()
            ->where('group_id', $group->id)->whereIn('id', $visibleIds)->where('type', BozjaHolster::TYPE_REFILL)
            ->with('items')->orderByDesc('is_default')->orderBy('id')->get() : collect([$holster]);

        return [
            'body' => $body,
            'legacy_body_html' => is_string($holster->guide) ? $this->markdown->legacyHtml($holster->guide) : null,
            'body_html' => is_array($holster->guide) ? $this->documents->html($body) : $this->markdown->legacyHtml($holster->guide ?? ''),
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

    public function management(Group $group, ?User $user = null): array
    {
        $this->synchronize($group);
        $collectionId = $this->collectionId($group);
        $levels = $user ? $this->policy->levels($user, $group) : ['everyone'];

        return [
            'collection_id' => $collectionId,
            'active_count' => BozjaHolster::where('group_id', $group->id)->where('is_active', true)->count(),
            'resources' => $collectionId ? ResourceSummaryResource::collection(GroupResource::where('group_id', $group->id)->whereNotNull('holster_id')->withAvailableSource()->whereIn('management_access_level', $levels)->with(['holster', 'publishedRevision'])->orderBy('id')->get())->resolve() : [],
        ];
    }
}
