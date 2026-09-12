<?php

namespace App\Support\Seo;

use App\Models\Activity;
use App\Models\Group;
use App\Services\Groups\GroupEmbedImageService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class ServerMeta
{
    private const DEFAULT_IMAGE = '/landing.png';

    public function __construct(
        private readonly GroupEmbedImageService $groupEmbedImageService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->build([
            'title' => null,
            'description' => $this->metaTranslation('seo.defaults.description'),
            'image' => self::DEFAULT_IMAGE,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function home(): array
    {
        return $this->build([
            'title' => $this->metaTranslation('seo.home.title'),
            'description' => $this->metaTranslation('seo.home.description'),
            'image' => self::DEFAULT_IMAGE,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function runDiscovery(): array
    {
        return $this->build([
            'title' => $this->metaTranslation('seo.discovery.runs_title'),
            'description' => $this->metaTranslation('seo.discovery.runs_description'),
            'image' => self::DEFAULT_IMAGE,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function groupDiscovery(): array
    {
        return $this->build([
            'title' => $this->metaTranslation('seo.discovery.groups_title'),
            'description' => $this->metaTranslation('seo.discovery.groups_description'),
            'image' => self::DEFAULT_IMAGE,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function group(Group $group): array
    {
        $embedImage = $this->groupEmbedImageService->urlFor($group) ?: self::DEFAULT_IMAGE;

        return $this->build([
            'title' => $group->name,
            'description' => filled($group->description)
                ? Str::limit((string) $group->description, 180)
                : $this->metaTranslation('seo.groups.profile_description', [
                    'group' => $group->name,
                    'datacenter' => $group->datacenter ?? 'FFXIV',
                ]),
            'image' => $embedImage,
            'images' => [$embedImage],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function groupInvite(Group $group): array
    {
        $embedImage = $this->groupEmbedImageService->urlFor($group) ?: self::DEFAULT_IMAGE;

        return $this->build([
            'title' => $this->metaTranslation('seo.invite.group_title', [
                'group' => $group->name,
            ]),
            'description' => filled($group->description)
                ? Str::limit((string) $group->description, 180)
                : $this->metaTranslation('seo.invite.group_description', [
                    'group' => $group->name,
                    'datacenter' => $group->datacenter ?? 'FFXIV',
                ]),
            'image' => $embedImage,
            'images' => [$embedImage],
        ]);
    }

    /** Build previews from the already-authorized public reader payload. */
    public function resourceLibrary(array $page): array
    {
        $customization = $page['library']['customization'];
        $resource = $page['resource'] ?? null;
        $article = $resource && ! $resource['is_home'] ? $resource : null;
        $collection = collect($page['collections'])->firstWhere('id', $page['reader']['selected_collection_id']);
        $title = $article['title'] ?? $collection['name'] ?? null;
        $imageId = $article['metadata_image_id'] ?? $customization['sharing_image_id']
            ?? $customization['banner_image_id'] ?? $customization['logo_image_id'] ?? null;

        return $this->build([
            'title' => $title ? $title.' - '.$customization['title'] : $customization['title'],
            'description' => Str::limit(($article['description'] ?? '') ?: $customization['introduction'], 180),
            'url' => $article
                ? (($article['source_type'] ?? null) === 'holster'
                    ? route('public-resources.holsters.show', ['group' => $page['group']['slug'], 'holster' => $article['holster_id']])
                    : route('public-resources.show', ['group' => $page['group']['slug'], 'slug' => $article['slug']]))
                : ($collection
                    ? route('public-resources.collections.show', ['group' => $page['group']['slug'], 'collectionSlug' => $collection['slug']])
                    : route('public-resources.index', ['group' => $page['group']['slug']])),
            'image' => $imageId ? route('public-resources.images.show', ['image' => $imageId]) : self::DEFAULT_IMAGE,
            'type' => $article ? 'article' : 'website',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function activity(Group $group, Activity $activity): array
    {
        $activityName = $this->activityDisplayName($activity);
        $title = filled($activity->title) ? (string) $activity->title : $activityName;

        return $this->build([
            'title' => $title,
            'description' => filled($activity->description)
                ? Str::limit((string) $activity->description, 180)
                : $this->activityDescription($title, $activityName, $group, $activity),
            'image' => $activity->activityTypeVersion?->banner_image_url
                ?: $activity->activityTypeVersion?->small_image_url
                ?: self::DEFAULT_IMAGE,
            'type' => 'event',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function build(array $overrides): array
    {
        $siteName = $this->metaTranslation('title') ?: config('app.name', 'FullParty');

        $meta = array_merge([
            'site_name' => $siteName,
            'title' => null,
            'description' => $this->metaTranslation('seo.defaults.description'),
            'type' => 'website',
            'url' => request()->fullUrl(),
            'image' => self::DEFAULT_IMAGE,
            'robots' => 'index, follow',
        ], $overrides);

        $meta['image'] = $this->absoluteUrl($meta['image']);
        $meta['images'] = $this->absoluteImageUrls($meta['images'] ?? [$meta['image']]);

        return $meta;
    }

    private function activityDescription(string $title, string $activityName, Group $group, Activity $activity): string
    {
        $description = $this->metaTranslation('seo.activities.overview_description', [
            'title' => $title,
            'group' => $group->name,
        ]);

        $details = collect([
            $activityName !== $title ? $activityName : null,
            $activity->starts_at
                ? $activity->starts_at->timezone('UTC')->format('j M Y, H:i').' UTC'
                : null,
            $activity->datacenter,
        ])
            ->filter()
            ->implode(' - ');

        return $details !== '' ? "{$description} {$details}." : $description;
    }

    private function activityDisplayName(Activity $activity): string
    {
        $name = $this->localizedValue(
            $activity->activityTypeVersion?->name
                ?: $activity->activityType?->draft_name
                ?: null
        );

        return $name !== ''
            ? $name
            : ($activity->activityType?->slug ?: 'FullParty run');
    }

    /**
     * @param  array<string, string|null>|null  $value
     */
    private function localizedValue(?array $value): string
    {
        if (! $value) {
            return '';
        }

        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.fallback_locale', 'en');

        foreach ([$locale, $fallbackLocale] as $key) {
            $candidate = $value[$key] ?? null;

            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ($value as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * @param  array<string, string|int|float|null>  $replace
     */
    private function metaTranslation(string $key, array $replace = []): string
    {
        $catalog = $this->metaCatalog(app()->getLocale());
        $value = Arr::get($catalog, $key);

        if (! is_string($value) || trim($value) === '') {
            $value = Arr::get($this->metaCatalog((string) config('app.fallback_locale', 'en')), $key);
        }

        if (! is_string($value)) {
            return '';
        }

        foreach ($replace as $replaceKey => $replaceValue) {
            $value = str_replace('{'.$replaceKey.'}', (string) $replaceValue, $value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function metaCatalog(string $locale): array
    {
        static $catalogs = [];

        if (array_key_exists($locale, $catalogs)) {
            return $catalogs[$locale];
        }

        $path = lang_path($locale.'/meta.json');

        if (! File::exists($path)) {
            return $catalogs[$locale] = [];
        }

        $catalog = json_decode((string) File::get($path), true);

        return $catalogs[$locale] = is_array($catalog) ? $catalog : [];
    }

    private function absoluteUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return url('/'.ltrim($url, '/'));
    }

    /**
     * @return array<int, string>
     */
    private function absoluteImageUrls(mixed $urls): array
    {
        if (! is_array($urls)) {
            $urls = [$urls];
        }

        return collect($urls)
            ->map(fn (mixed $url) => is_string($url) ? $this->absoluteUrl($url) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
