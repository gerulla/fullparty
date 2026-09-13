<?php

namespace App\Services\Moderation;

use App\Http\Resources\Moderation\ModerationCaseResource;
use App\Http\Resources\Moderation\ReportProfileResource;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\BozjaHolster;
use App\Models\Group;
use App\Models\GroupMembershipApplication;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Models\GroupUserNote;
use App\Models\ModerationCase;
use App\Models\User;
use App\Services\RichText\RichTextDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ReportCasePresenter
{
    public function __construct(private readonly ReportTargetRegistry $targets, private readonly ReportContentPreview $previews, private readonly RichTextDocument $documents) {}

    public function detail(ModerationCase $case): array
    {
        $case->load(['assignee:id,name', 'subjectUser.homeProfile', 'subjectUser.characters',
            'reports.reporter.homeProfile', 'reports.reporter.characters', 'actions.admin:id,name',
            'feedback' => fn ($query) => $query->withCount(['recipients', 'recipients as acknowledged_count' => fn ($q) => $q->whereNotNull('acknowledged_at')]),
        ])->loadCount('reports');
        $adapter = $this->targets->adapter($case->target_type);
        $target = $adapter->modelClass()::find($case->target_id);
        $group = $this->group($target);
        $snapshot = $target ? $adapter->snapshot($target) : null;
        $holster = $target instanceof BozjaHolster ? $target : ($target instanceof GroupResource ? $target->holster : null);
        if ($holster && $target instanceof GroupResource) {
            // Public readers mask hidden holster names; administrators need the actual source title.
            $names = array_filter($holster->name ?? []);
            $snapshot['title'] = $snapshot['content']['title'] = $names[app()->getLocale()] ?? $names['en'] ?? reset($names) ?: $case->title;
        }
        if ($target instanceof GroupMembershipApplication) {
            $snapshot['content']['form_snapshot'] = $target->form_snapshot;
        }
        $images = $this->images($case, $target, $snapshot);
        $imageUrls = $images->mapWithKeys(fn ($image) => [$image->uuid => route('admin.reports.asset', [$case, $image], false)])->all();
        $resource = (new ModerationCaseResource($case))->resolve();
        $resource['reports'] = collect($resource['reports'])->map(function ($entry) {
            // Evidence uses its retained snapshot, never the target's newer content.
            $entry['preview'] = $this->previews->build($entry['snapshot']);

            return $entry;
        })->all();
        $run = $target instanceof ActivityApplication ? $target->activity : ($target instanceof Activity ? $target : null);
        $owner = $target instanceof GroupResource ? User::find($target->author_user_id) : null;

        return [
            'case' => $resource, 'target_exists' => $target !== null,
            'can_hide' => $target && $adapter->canHide(), 'is_hidden' => (bool) $target?->moderation_hidden_at,
            'current_content' => $snapshot, 'subject_user' => $this->profile($case->subjectUser),
            'context' => [
                'preview' => $snapshot ? $this->previews->build($snapshot, $imageUrls) : null,
                'url' => $this->url($target, $group),
                'created_at' => $target?->created_at?->toIso8601String(), 'updated_at' => $target?->updated_at?->toIso8601String(),
                'profile' => $target instanceof User ? $this->profile($target) : null,
                'owner' => $this->profile($owner),
                'member' => $target instanceof GroupUserNote ? $this->profile($target->user) : null,
                'group' => $group ? $group->only(['id', 'name', 'slug', 'description', 'profile_picture_url', 'banner_image_url', 'datacenter', 'is_visible']) + [
                    'url' => route('groups.dashboard', $group, false), 'owner' => $this->profile($group->owner),
                    'member_count' => $group->memberships()->count(),
                ] : null,
                'run' => $run ? ['id' => $run->id, 'title' => $run->title, 'description' => $run->description,
                    'starts_at' => $run->starts_at?->toIso8601String(), 'url' => $this->url($run, $group)] : null,
                'collection' => $target instanceof GroupResource ? $target->collection?->name : null,
                'images' => $images->map(fn ($image) => $image->only(['id', 'uuid', 'original_name', 'alt_text', 'caption', 'width', 'height']) + ['url' => $imageUrls[$image->uuid]])->values()->all(),
                'cover_url' => $imageUrls[$snapshot['content']['metadata_image_id'] ?? ''] ?? null,
                'items' => $holster ? $holster->items->map(fn ($item) => [
                    'name' => $item->name, 'icon_url' => $item->icon_url, 'quantity' => (int) $item->pivot->quantity,
                ])->all() : [],
            ],
        ];
    }

    public function images(ModerationCase $case, ?Model $target = null, ?array $snapshot = null): Collection
    {
        $adapter = $this->targets->adapter($case->target_type);
        $target ??= $adapter->modelClass()::find($case->target_id);
        if ($target instanceof GroupResourceImage) {
            return collect([$target]);
        }
        if (! $target instanceof GroupResource && ! $target instanceof BozjaHolster) {
            return collect();
        }
        $snapshot ??= $adapter->snapshot($target);
        $content = $snapshot['content'] ?? [];
        $document = $content['body'] ?? $content['guide'] ?? null;
        $uuids = [...($content['image_ids'] ?? []), $content['metadata_image_id'] ?? null];
        if (is_array($document)) {
            foreach ($this->documents->imageUrls($document) as $url) {
                $uuids[] = basename(parse_url($url, PHP_URL_PATH) ?? '');
            }
        }

        // Admin preview access is restricted to images referenced by this target in its own group.
        return GroupResourceImage::where('group_id', $target->group_id)->whereIn('uuid', array_filter($uuids))->get();
    }

    private function group(?Model $target): ?Group
    {
        return match (true) {
            $target instanceof Group => $target,
            $target instanceof ActivityApplication => $target->activity?->group,
            $target instanceof GroupResourceImage => Group::find($target->group_id),
            $target instanceof User, $target === null => null,
            default => $target->group,
        };
    }

    private function profile(?User $user): ?array
    {
        return $user ? (new ReportProfileResource($user->loadMissing(['homeProfile', 'characters'])))->resolve() : null;
    }

    private function url(?Model $target, ?Group $group): ?string
    {
        if (! $group) {
            return null;
        }

        return match (true) {
            $target instanceof Group => route('groups.dashboard', $group, false),
            $target instanceof Activity => route('groups.activities.overview', [$group, $target], false),
            $target instanceof ActivityApplication => route('groups.activities.overview', [$group, $target->activity], false),
            $target instanceof GroupResource => route('groups.dashboard.resources.show', [$group, $target->uuid], false),
            $target instanceof BozjaHolster => route('groups.dashboard.resources.holsters.show', [$group, $target], false),
            default => null,
        };
    }
}
