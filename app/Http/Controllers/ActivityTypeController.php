<?php

namespace App\Http\Controllers;

use App\Models\ActivityTag;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
use App\Services\ActivityTypes\ActivityTypeDraftValidator;
use App\Services\ActivityTypes\ActivityTypePublishingService;
use App\Services\AuditLogger;
use App\Services\ManagedImageStorage;
use App\Support\ActivityCompositionPresets;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityTypeController extends Controller
{
    private const IMAGE_DIRECTORY = 'activity-types';

    private const INDEX_PER_PAGE = 12;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ManagedImageStorage $managedImageStorage,
        private readonly ActivityTypeDraftValidator $draftValidator,
        private readonly ActivityTypePublishingService $publishingService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdminAccess();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        $activityTypeQuery = ActivityType::query()
            ->with(['creator:id,name', 'currentPublishedVersion.publisher:id,name', 'versions.publisher:id,name', 'tags:id,name']);

        if ($search !== '') {
            $this->applyIndexSearch($activityTypeQuery, $search);
        }

        $activityTypes = $activityTypeQuery
            ->latest('updated_at')
            ->paginate(self::INDEX_PER_PAGE)
            ->withQueryString();

        return Inertia::render('Admin/ActivityTypes', [
            'activityTypes' => [
                'data' => $activityTypes
                    ->getCollection()
                    ->map(fn (ActivityType $activityType) => $this->transformActivityType($activityType))
                    ->values(),
                'meta' => [
                    'current_page' => $activityTypes->currentPage(),
                    'last_page' => $activityTypes->lastPage(),
                    'per_page' => $activityTypes->perPage(),
                    'total' => $activityTypes->total(),
                    'from' => $activityTypes->firstItem(),
                    'to' => $activityTypes->lastItem(),
                ],
            ],
            'filters' => [
                'search' => $search,
            ],
            'totalActivityTypes' => ActivityType::query()->count(),
            'schemaReference' => $this->schemaReference(),
        ]);
    }

    public function create(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/ActivityTypesCreate', [
            'schemaReference' => $this->schemaReference(),
            'existingTags' => $this->availableTags(),
        ]);
    }

    public function edit(ActivityType $activityType): Response
    {
        $this->authorizeAdminAccess();

        $activityType->load(['creator:id,name', 'currentPublishedVersion.publisher:id,name', 'versions.publisher:id,name', 'tags:id,name']);

        return Inertia::render('Admin/ActivityTypesEdit', [
            'activityType' => $this->transformActivityType($activityType),
            'schemaReference' => $this->schemaReference(),
            'existingTags' => $this->availableTags(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $this->normalizeNullableDraftMetadata($request);
        $validated = $request->validate($this->rules());
        $this->draftValidator->validate($validated);
        $tagNames = $this->extractTagNames($validated);
        $smallImageUrl = $this->managedImageStorage->uploadImageIfPresent(
            file: $request->file('draft_small_image'),
            directory: self::IMAGE_DIRECTORY,
        );
        $bannerImageUrl = $this->managedImageStorage->uploadImageIfPresent(
            file: $request->file('draft_banner_image'),
            directory: self::IMAGE_DIRECTORY,
        );

        $activityType = ActivityType::create([
            ...collect($validated)->except('tags', 'draft_small_image', 'draft_banner_image')->all(),
            'draft_small_image_url' => $smallImageUrl,
            'draft_banner_image_url' => $bannerImageUrl,
            'created_by_user_id' => auth()->id(),
        ]);
        $this->syncTags($activityType, $tagNames);

        $this->auditLogger->log(
            action: 'admin.activity_type.created',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.activity_type.created',
            actor: auth()->user(),
            subject: $activityType,
            metadata: [
                ...$this->activityTypeSnapshot($activityType),
                'activity_type_name' => $this->resolveAuditActivityTypeName($activityType),
            ],
        );

        return redirect()
            ->route('admin.activity-types.index')
            ->with('success', 'activity_type_created');
    }

    public function update(Request $request, ActivityType $activityType): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $originalValues = $this->activityTypeSnapshot($activityType);
        $this->normalizeNullableDraftMetadata($request);
        $validated = $request->validate($this->rules($activityType->id));
        $this->draftValidator->validate($validated);
        $tagNames = $this->extractTagNames($validated);
        $smallImageUrl = $this->managedImageStorage->replaceUploadedImageIfPresent(
            currentUrl: $activityType->draft_small_image_url,
            file: $request->file('draft_small_image'),
            directory: self::IMAGE_DIRECTORY,
        );
        $bannerImageUrl = $this->managedImageStorage->replaceUploadedImageIfPresent(
            currentUrl: $activityType->draft_banner_image_url,
            file: $request->file('draft_banner_image'),
            directory: self::IMAGE_DIRECTORY,
        );

        $activityType->update([
            ...collect($validated)->except('tags', 'draft_small_image', 'draft_banner_image')->all(),
            'draft_small_image_url' => $smallImageUrl,
            'draft_banner_image_url' => $bannerImageUrl,
        ]);
        $this->syncTags($activityType, $tagNames);

        $updatedValues = $this->activityTypeSnapshot($activityType->fresh()->load('tags:id,name'));
        $changes = $this->buildChanges($originalValues, $updatedValues);

        if ($changes !== []) {
            $this->auditLogger->log(
                action: 'admin.activity_type.updated',
                severity: AuditSeverity::CRITICAL,
                scopeType: AuditScope::ADMIN,
                scopeId: null,
                message: 'audit_log.events.admin.activity_type.updated',
                actor: auth()->user(),
                subject: $activityType,
                metadata: [
                    'activity_type_name' => $this->resolveAuditActivityTypeName($activityType->fresh()),
                    'changed_fields' => array_keys($changes),
                    'changes' => $changes,
                ],
            );
        }

        return redirect()
            ->route('admin.activity-types.index')
            ->with('success', 'activity_type_updated');
    }

    public function duplicate(ActivityType $activityType): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $activityType->loadMissing('tags:id,name');
        $clone = DB::transaction(function () use ($activityType): ActivityType {
            $clone = ActivityType::create([
                'slug' => $this->uniqueCloneSlug($activityType->slug),
                'draft_name' => $this->cloneLocalizedName($activityType->draft_name),
                'draft_description' => $activityType->draft_description,
                'draft_small_image_url' => $this->managedImageStorage->copyManagedImage($activityType->draft_small_image_url, self::IMAGE_DIRECTORY),
                'draft_banner_image_url' => $this->managedImageStorage->copyManagedImage($activityType->draft_banner_image_url, self::IMAGE_DIRECTORY),
                'draft_difficulty' => $activityType->draft_difficulty,
                'draft_default_min_item_level' => $activityType->draft_default_min_item_level,
                'draft_layout_schema' => $activityType->draft_layout_schema,
                'draft_slot_schema' => $activityType->draft_slot_schema,
                'draft_application_schema' => $activityType->draft_application_schema,
                'draft_roster_summary_presets' => $activityType->draft_roster_summary_presets ?? [],
                'draft_progress_schema' => $activityType->draft_progress_schema,
                'draft_bench_size' => $activityType->draft_bench_size,
                'draft_prog_points' => $activityType->draft_prog_points,
                'draft_fflogs_zone_id' => $activityType->draft_fflogs_zone_id,
                'is_active' => true,
                'created_by_user_id' => auth()->id(),
            ]);

            $this->syncTags($clone, $activityType->tags->pluck('name')->values()->all());

            return $clone;
        });

        $this->auditLogger->log(
            action: 'admin.activity_type.cloned',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.activity_type.cloned',
            actor: auth()->user(),
            subject: $clone,
            metadata: [
                ...$this->activityTypeSnapshot($clone->load('tags:id,name')),
                'source_activity_type_id' => $activityType->id,
                'source_activity_type_slug' => $activityType->slug,
                'activity_type_name' => $this->resolveAuditActivityTypeName($clone),
            ],
        );

        return redirect()
            ->route('admin.activity-types.edit', $clone)
            ->with('success', 'activity_type_cloned');
    }

    public function publish(ActivityType $activityType): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $this->publishingService->publish($activityType, auth()->user());

        return redirect()->back()->with('success', 'activity_type_published');
    }

    public function publishAll(): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $count = $this->publishingService->publishAll(auth()->user());

        return redirect()->back()
            ->with('success', 'activity_types_published')
            ->with('flash_data', ['published_count' => $count]);
    }

    public function destroy(ActivityType $activityType): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $snapshot = $this->activityTypeSnapshot($activityType);
        $activityType->update(['is_active' => false]);

        $this->auditLogger->log(
            action: 'admin.activity_type.archived',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.activity_type.archived',
            actor: auth()->user(),
            subject: $activityType,
            metadata: [
                ...$snapshot,
                'activity_type_name' => $this->resolveAuditActivityTypeName($activityType),
            ],
        );

        return redirect()->back()->with('success', 'activity_type_archived');
    }

    private function applyIndexSearch(Builder $query, string $search): void
    {
        $likeSearch = '%'.mb_strtolower($search).'%';

        $query->where(function (Builder $query) use ($likeSearch) {
            $query
                ->whereRaw('LOWER(slug) LIKE ?', [$likeSearch])
                ->orWhereRaw("LOWER(COALESCE(draft_difficulty, '')) LIKE ?", [$likeSearch])
                ->orWhereHas('tags', fn (Builder $tagQuery) => $tagQuery
                    ->whereRaw('LOWER(name) LIKE ?', [$likeSearch]));

            $this->orWhereJsonTextLike($query, 'draft_name', $likeSearch);
            $this->orWhereJsonTextLike($query, 'draft_description', $likeSearch);
        });
    }

    private function orWhereJsonTextLike(Builder $query, string $column, string $likeSearch): void
    {
        $driver = $query->getModel()->getConnection()->getDriverName();

        $expression = match ($driver) {
            'pgsql' => "LOWER(COALESCE({$column}::text, '')) LIKE ?",
            'sqlite' => "LOWER(COALESCE({$column}, '')) LIKE ?",
            default => "LOWER(COALESCE(CAST({$column} AS CHAR), '')) LIKE ?",
        };

        $query->orWhereRaw($expression, [$likeSearch]);
    }

    /**
     * @param  array<string, string>|null  $localizedName
     * @return array<string, string>
     */
    private function cloneLocalizedName(?array $localizedName): array
    {
        $name = [];
        foreach (['en', 'de', 'fr', 'ja'] as $locale) {
            $value = $localizedName[$locale] ?? $localizedName['en'] ?? __('ui.activity_type', [], $locale);
            $name[$locale] = __('ui.activity_copy', ['name' => filled($value) ? $value : __('ui.activity_type', [], $locale)], $locale);
        }

        return $name;
    }

    private function uniqueCloneSlug(string $sourceSlug): string
    {
        $sourceSlug = Str::slug($sourceSlug) ?: 'activity-type';
        $index = 1;

        do {
            $suffix = $index === 1 ? '-copy' : "-copy-{$index}";
            $candidate = Str::limit($sourceSlug, 255 - strlen($suffix), '').$suffix;
            $index++;
        } while (ActivityType::query()->where('slug', $candidate)->exists());

        return $candidate;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    private function rules(?int $activityTypeId = null): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('activity_types', 'slug')->ignore($activityTypeId),
            ],
            'draft_name' => ['required', 'array', 'min:1'],
            'draft_name.*' => ['required', 'string', 'max:255'],
            'draft_description' => ['nullable', 'array'],
            'draft_description.*' => ['nullable', 'string'],
            'draft_small_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
            'draft_banner_image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
            'draft_difficulty' => ['sometimes', 'string', Rule::in(ActivityType::DIFFICULTIES)],
            'draft_default_min_item_level' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'draft_layout_schema' => ['required', 'array'],
            'draft_slot_schema' => ['required', 'array'],
            'draft_application_schema' => ['required', 'array'],
            'draft_roster_summary_presets' => ['nullable', 'array'],
            'draft_progress_schema' => ['required', 'array'],
            'draft_bench_size' => ['sometimes', 'integer', 'min:0', 'max:24'],
            'draft_prog_points' => ['nullable', 'array'],
            'draft_fflogs_zone_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function normalizeNullableDraftMetadata(Request $request): void
    {
        if ($request->input('draft_default_min_item_level') === '') {
            $request->merge([
                'draft_default_min_item_level' => null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, string>
     */
    private function extractTagNames(array $validated): array
    {
        return collect($validated['tags'] ?? [])
            ->map(fn (mixed $tag) => is_string($tag) ? trim($tag) : null)
            ->filter(fn (?string $tag) => filled($tag))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $tagNames
     */
    private function syncTags(ActivityType $activityType, array $tagNames): void
    {
        $tagIds = collect($tagNames)
            ->map(fn (string $tagName) => ActivityTag::firstOrCreate(['name' => $tagName])->id)
            ->all();

        $activityType->tags()->sync($tagIds);

        ActivityTag::query()
            ->doesntHave('activityTypes')
            ->delete();
    }

    private function authorizeAdminAccess(): void
    {
        if (! auth()->user()?->is_admin) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaReference(): array
    {
        return [
            'supportedFieldTypes' => [
                'text',
                'textarea',
                'number',
                'boolean',
                'single_select',
                'multi_select',
                'holster_pair',
                'holster_pair_list',
                'url',
            ],
            'supportedOptionSources' => $this->draftValidator->supportedOptionSources(),
            'rosterSummarySources' => [
                'character_classes',
                'phantom_jobs',
            ],
            'rosterSummaryComparisonModes' => [
                'at_least',
                'exactly',
                'at_most',
            ],
            'rosterSummaryScopeTypes' => [
                'all_slots',
                'slot_group',
                'slot_group_set',
            ],
            'activityDifficulties' => ActivityType::DIFFICULTIES,
            'layoutPresets' => ActivityCompositionPresets::layoutPresets(),
            'compositionPresets' => ActivityCompositionPresets::compositionPresets(),
            'rosterSummarySourceOptions' => [
                'character_classes' => CharacterClass::query()
                    ->orderBy('role')
                    ->orderBy('name')
                    ->get(['id', 'name', 'shorthand', 'role'])
                    ->map(fn (CharacterClass $class) => [
                        'value' => $class->id,
                        'label' => filled($class->shorthand)
                            ? sprintf('%s (%s)', $class->name, $class->shorthand)
                            : $class->name,
                        'meta' => [
                            'role' => $class->role,
                            'shorthand' => $class->shorthand,
                        ],
                    ])
                    ->values()
                    ->all(),
                'phantom_jobs' => PhantomJob::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (PhantomJob $phantomJob) => [
                        'value' => $phantomJob->id,
                        'label' => $phantomJob->name,
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformActivityType(ActivityType $activityType): array
    {
        $currentVersion = $activityType->currentPublishedVersion;

        return [
            'id' => $activityType->id,
            'slug' => $activityType->slug,
            'is_active' => $activityType->is_active,
            'draft_name' => $activityType->draft_name,
            'draft_description' => $activityType->draft_description,
            'draft_small_image_url' => $activityType->draft_small_image_url,
            'draft_banner_image_url' => $activityType->draft_banner_image_url,
            'draft_difficulty' => $activityType->draft_difficulty,
            'draft_default_min_item_level' => $activityType->draft_default_min_item_level,
            'tags' => $activityType->tags->pluck('name')->values()->all(),
            'draft_layout_schema' => $activityType->draft_layout_schema,
            'draft_slot_schema' => $activityType->draft_slot_schema,
            'draft_application_schema' => $activityType->draft_application_schema,
            'draft_roster_summary_presets' => $activityType->draft_roster_summary_presets ?? [],
            'draft_progress_schema' => $activityType->draft_progress_schema,
            'draft_bench_size' => $activityType->draft_bench_size,
            'draft_prog_points' => $activityType->draft_prog_points,
            'draft_fflogs_zone_id' => $activityType->draft_fflogs_zone_id,
            'created_by' => $activityType->creator?->name,
            'current_published_version' => $currentVersion ? [
                'id' => $currentVersion->id,
                'version' => $currentVersion->version,
                'small_image_url' => $currentVersion->small_image_url,
                'banner_image_url' => $currentVersion->banner_image_url,
                'difficulty' => $currentVersion->difficulty,
                'default_min_item_level' => $currentVersion->default_min_item_level,
                'bench_size' => $currentVersion->bench_size,
                'fflogs_zone_id' => $currentVersion->fflogs_zone_id,
                'roster_summary_presets' => $currentVersion->roster_summary_presets ?? [],
                'published_at' => $currentVersion->published_at?->toIso8601String(),
                'published_by' => $currentVersion->publisher?->name,
            ] : null,
            'versions' => $activityType->versions
                ->map(fn (ActivityTypeVersion $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'published_at' => $version->published_at?->toIso8601String(),
                    'published_by' => $version->publisher?->name,
                ])
                ->values(),
            'updated_at' => $activityType->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityTypeSnapshot(ActivityType $activityType): array
    {
        return [
            'id' => $activityType->id,
            'slug' => $activityType->slug,
            'draft_name' => $activityType->draft_name,
            'draft_description' => $activityType->draft_description,
            'draft_small_image_url' => $activityType->draft_small_image_url,
            'draft_banner_image_url' => $activityType->draft_banner_image_url,
            'draft_difficulty' => $activityType->draft_difficulty,
            'draft_default_min_item_level' => $activityType->draft_default_min_item_level,
            'tags' => $activityType->tags->pluck('name')->values()->all(),
            'draft_layout_schema' => $activityType->draft_layout_schema,
            'draft_slot_schema' => $activityType->draft_slot_schema,
            'draft_application_schema' => $activityType->draft_application_schema,
            'draft_roster_summary_presets' => $activityType->draft_roster_summary_presets ?? [],
            'draft_progress_schema' => $activityType->draft_progress_schema,
            'draft_bench_size' => $activityType->draft_bench_size,
            'draft_prog_points' => $activityType->draft_prog_points,
            'draft_fflogs_zone_id' => $activityType->draft_fflogs_zone_id,
            'is_active' => $activityType->is_active,
            'current_published_version_id' => $activityType->current_published_version_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $originalValues
     * @param  array<string, mixed>  $updatedValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function buildChanges(array $originalValues, array $updatedValues): array
    {
        return collect($updatedValues)
            ->keys()
            ->filter(fn (string $field) => $originalValues[$field] !== $updatedValues[$field])
            ->mapWithKeys(fn (string $field) => [
                $field => [
                    'old' => $originalValues[$field],
                    'new' => $updatedValues[$field],
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function availableTags(): array
    {
        return ActivityTag::query()
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();
    }

    private function resolveAuditActivityTypeName(ActivityType $activityType): string
    {
        $draftName = $activityType->draft_name;

        if (is_array($draftName)) {
            foreach (['en', config('app.fallback_locale')] as $locale) {
                if (is_string($locale) && filled($draftName[$locale] ?? null)) {
                    return trim((string) $draftName[$locale]);
                }
            }

            foreach ($draftName as $value) {
                if (filled($value)) {
                    return trim((string) $value);
                }
            }
        }

        return $activityType->slug;
    }
}
