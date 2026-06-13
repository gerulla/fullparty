<?php

namespace App\Http\Controllers;

use App\Models\ActivityTag;
use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Models\CharacterClass;
use App\Models\PhantomJob;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ActivityTypeController extends Controller
{
    private const IMAGE_DIRECTORY = 'activity-types';

    private const INDEX_PER_PAGE = 12;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ManagedImageStorage $managedImageStorage,
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
        $this->validateDraftSchema($validated);
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
        $this->validateDraftSchema($validated);
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

    public function publish(ActivityType $activityType): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $draftPayload = [
            'draft_name' => $activityType->draft_name,
            'draft_description' => $activityType->draft_description,
            'draft_small_image_url' => $activityType->draft_small_image_url,
            'draft_banner_image_url' => $activityType->draft_banner_image_url,
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
        ];

        $this->validateDraftSchema($draftPayload);

        $version = DB::transaction(function () use ($activityType) {
            $nextVersion = ((int) $activityType->versions()->max('version')) + 1;

            $version = $activityType->versions()->create([
                'version' => $nextVersion,
                'name' => $activityType->draft_name,
                'description' => $activityType->draft_description,
                'small_image_url' => $this->managedImageStorage->copyManagedImage($activityType->draft_small_image_url, self::IMAGE_DIRECTORY),
                'banner_image_url' => $this->managedImageStorage->copyManagedImage($activityType->draft_banner_image_url, self::IMAGE_DIRECTORY),
                'difficulty' => $activityType->draft_difficulty ?? ActivityType::DIFFICULTY_NORMAL,
                'default_min_item_level' => $activityType->draft_default_min_item_level,
                'layout_schema' => $activityType->draft_layout_schema,
                'slot_schema' => $activityType->draft_slot_schema,
                'application_schema' => $activityType->draft_application_schema,
                'roster_summary_presets' => $activityType->draft_roster_summary_presets,
                'progress_schema' => $activityType->draft_progress_schema,
                'bench_size' => $activityType->draft_bench_size,
                'prog_points' => $activityType->draft_prog_points,
                'fflogs_zone_id' => $activityType->draft_fflogs_zone_id,
                'published_by_user_id' => auth()->id(),
                'published_at' => now(),
            ]);

            $activityType->update([
                'current_published_version_id' => $version->id,
            ]);

            return $version;
        });

        $this->auditLogger->log(
            action: 'admin.activity_type.published',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.activity_type.published',
            actor: auth()->user(),
            subject: $activityType->fresh(),
            metadata: [
                'activity_type_version_id' => $version->id,
                'published_version' => $version->version,
                'slug' => $activityType->slug,
                'draft_name' => $activityType->draft_name,
                'activity_type_name' => $this->resolveAuditActivityTypeName($activityType),
            ],
        );

        return redirect()->back()->with('success', 'activity_type_published');
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateDraftSchema(array $validated): void
    {
        $name = $validated['draft_name'] ?? null;
        $layoutSchema = $validated['draft_layout_schema'] ?? null;
        $slotSchema = $validated['draft_slot_schema'] ?? null;
        $applicationSchema = $validated['draft_application_schema'] ?? null;
        $rosterSummaryPresets = $validated['draft_roster_summary_presets'] ?? [];
        $progressSchema = $validated['draft_progress_schema'] ?? null;
        $benchSize = $validated['draft_bench_size'] ?? 0;
        $progPoints = $validated['draft_prog_points'] ?? null;
        $tags = $validated['tags'] ?? null;
        $difficulty = $validated['draft_difficulty'] ?? ActivityType::DIFFICULTY_NORMAL;
        $defaultMinItemLevel = $validated['draft_default_min_item_level'] ?? null;

        if (! is_array($name) || ! array_key_exists('en', $name) || blank($name['en'])) {
            throw ValidationException::withMessages([
                'draft_name.en' => 'An English activity type name is required.',
            ]);
        }

        if (! is_array($layoutSchema) || ! isset($layoutSchema['groups']) || ! is_array($layoutSchema['groups']) || $layoutSchema['groups'] === []) {
            throw ValidationException::withMessages([
                'draft_layout_schema.groups' => 'At least one slot group is required.',
            ]);
        }

        foreach ($layoutSchema['groups'] as $index => $group) {
            if (! is_array($group)) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index" => 'Each slot group must be an object.',
                ]);
            }

            if (blank($group['key'] ?? null) || blank($group['size'] ?? null)) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index" => 'Each slot group requires a key and size.',
                ]);
            }

            if (! is_numeric($group['size']) || (int) $group['size'] < 1) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index.size" => 'Each slot group size must be at least 1.',
                ]);
            }

            $this->assertLocalizedValue($group['label'] ?? null, "draft_layout_schema.groups.$index.label");
            $this->validateCompositionHints(
                $group['composition_hints'] ?? null,
                (int) $group['size'],
                "draft_layout_schema.groups.$index.composition_hints",
            );
        }

        $this->validateSchemaFields($slotSchema, 'draft_slot_schema');
        $this->validateSchemaFields($applicationSchema, 'draft_application_schema', supportsAnySelection: true);
        $layoutGroupKeys = collect($layoutSchema['groups'])
            ->pluck('key')
            ->filter(fn (mixed $key) => filled($key))
            ->map(fn (mixed $key) => (string) $key)
            ->values()
            ->all();

        $this->validateRosterSummaryPresets($rosterSummaryPresets, 'draft_roster_summary_presets', $layoutGroupKeys);
        $this->validateProgressSchema($progressSchema, 'draft_progress_schema');
        $this->validateBenchSize($benchSize, 'draft_bench_size');
        $this->validateProgPoints($progPoints, 'draft_prog_points');
        $this->validateTags($tags, 'tags');
        $this->validateDiscoveryMetadata($difficulty, $defaultMinItemLevel);
    }

    private function validateCompositionHints(mixed $hints, int $groupSize, string $attribute): void
    {
        if ($hints === null) {
            return;
        }

        if (! is_array($hints)) {
            throw ValidationException::withMessages([
                $attribute => 'Composition hints must be an array.',
            ]);
        }

        $positions = [];

        foreach ($hints as $index => $hint) {
            if (! is_array($hint)) {
                throw ValidationException::withMessages([
                    "$attribute.$index" => 'Each composition hint must be an object.',
                ]);
            }

            $position = $hint['position'] ?? null;

            if (! is_numeric($position) || (int) $position < 1 || (int) $position > $groupSize) {
                throw ValidationException::withMessages([
                    "$attribute.$index.position" => 'Composition hint positions must point to an existing slot.',
                ]);
            }

            $position = (int) $position;

            if (in_array($position, $positions, true)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.position" => 'Each slot position can only have one composition hint object.',
                ]);
            }

            $positions[] = $position;

            if (! isset($hint['accepts']) || ! is_array($hint['accepts']) || $hint['accepts'] === []) {
                throw ValidationException::withMessages([
                    "$attribute.$index.accepts" => 'Each composition hint requires at least one accepted role or class.',
                ]);
            }

            $acceptKeys = [];

            foreach ($hint['accepts'] as $acceptIndex => $accept) {
                if (! is_array($accept)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex" => 'Each accepted composition value must be an object.',
                    ]);
                }

                $type = (string) ($accept['type'] ?? '');
                $key = (string) ($accept['key'] ?? '');

                if (! in_array($type, ['role', 'class'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex.type" => 'Composition hint types must be role or class.',
                    ]);
                }

                if (blank($key) || mb_strlen($key) > 50) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex.key" => 'Composition hint keys are required and must stay short.',
                    ]);
                }

                if ($type === 'role' && ! in_array($key, ActivityCompositionPresets::validRoleKeys(), true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex.key" => 'Unsupported composition role key.',
                    ]);
                }

                $acceptKey = "{$type}:{$key}";

                if (in_array($acceptKey, $acceptKeys, true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts.$acceptIndex.key" => 'Accepted composition values must be unique per slot.',
                    ]);
                }

                $acceptKeys[] = $acceptKey;
            }
        }
    }

    private function validateDiscoveryMetadata(mixed $difficulty, mixed $defaultMinItemLevel): void
    {
        if (! in_array($difficulty, ActivityType::DIFFICULTIES, true)) {
            throw ValidationException::withMessages([
                'draft_difficulty' => 'Unsupported activity difficulty.',
            ]);
        }

        if (is_null($defaultMinItemLevel)) {
            return;
        }

        if (! is_numeric($defaultMinItemLevel) || (int) $defaultMinItemLevel < 1 || (int) $defaultMinItemLevel > 9999) {
            throw ValidationException::withMessages([
                'draft_default_min_item_level' => 'Default minimum item level must be a valid positive number.',
            ]);
        }
    }

    private function normalizeNullableDraftMetadata(Request $request): void
    {
        if ($request->input('draft_default_min_item_level') === '') {
            $request->merge([
                'draft_default_min_item_level' => null,
            ]);
        }
    }

    private function validateBenchSize(mixed $benchSize, string $attribute): void
    {
        if (! is_numeric($benchSize) || (int) $benchSize < 0) {
            throw ValidationException::withMessages([
                $attribute => 'Bench size must be a valid non-negative number.',
            ]);
        }
    }

    private function validateTags(mixed $tags, string $attribute): void
    {
        if (is_null($tags)) {
            return;
        }

        if (! is_array($tags)) {
            throw ValidationException::withMessages([
                $attribute => 'Tags must be an array.',
            ]);
        }

        $normalizedTags = collect($tags)
            ->map(fn (mixed $tag) => is_string($tag) ? trim($tag) : null)
            ->filter(fn (?string $tag) => filled($tag))
            ->values();

        if ($normalizedTags->count() !== count($tags)) {
            throw ValidationException::withMessages([
                $attribute => 'Tags must only contain non-empty strings.',
            ]);
        }

        if ($normalizedTags->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                $attribute => 'Tags must be unique.',
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

    private function validateProgPoints(mixed $progPoints, string $attribute): void
    {
        if (is_null($progPoints)) {
            return;
        }

        if (! is_array($progPoints)) {
            throw ValidationException::withMessages([
                $attribute => 'Prog points must be an array.',
            ]);
        }

        foreach ($progPoints as $index => $progPoint) {
            if (! is_array($progPoint)) {
                throw ValidationException::withMessages([
                    "$attribute.$index" => 'Each prog point must be an object.',
                ]);
            }

            if (blank($progPoint['key'] ?? null)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.key" => 'Each prog point requires a key.',
                ]);
            }

            $this->assertLocalizedValue($progPoint['label'] ?? null, "$attribute.$index.label");
        }
    }

    private function validateProgressSchema(mixed $progressSchema, string $attribute): void
    {
        if (! is_array($progressSchema)) {
            throw ValidationException::withMessages([
                $attribute => 'Progress schema must be an object.',
            ]);
        }

        $milestones = $progressSchema['milestones'] ?? null;

        if (! is_array($milestones)) {
            throw ValidationException::withMessages([
                "$attribute.milestones" => 'Progress milestones must be an array.',
            ]);
        }

        foreach ($milestones as $index => $milestone) {
            if (! is_array($milestone)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index" => 'Each milestone must be an object.',
                ]);
            }

            if (blank($milestone['key'] ?? null)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.key" => 'Each milestone requires a key.',
                ]);
            }

            if (! is_numeric($milestone['order'] ?? null) || (int) $milestone['order'] < 1) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.order" => 'Each milestone requires a valid order.',
                ]);
            }

            $matcher = $milestone['fflogs_matcher'] ?? null;

            if (! is_array($matcher)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher" => 'Each milestone requires an FF Logs matcher.',
                ]);
            }

            $matcherType = $matcher['type'] ?? null;

            if (! in_array($matcherType, ['encounter', 'phase'], true)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.type" => 'Unsupported FF Logs matcher type.',
                ]);
            }

            if (! is_numeric($matcher['encounter_id'] ?? null) || (int) $matcher['encounter_id'] < 1) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.encounter_id" => 'Each milestone requires a valid FF Logs encounter ID.',
                ]);
            }

            if ($matcherType === 'phase' && (! is_numeric($matcher['phase_id'] ?? null) || (int) $matcher['phase_id'] < 1)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.phase_id" => 'Phase milestones require a valid FF Logs phase ID.',
                ]);
            }

            $this->assertLocalizedValue($milestone['label'] ?? null, "$attribute.milestones.$index.label");
        }
    }

    /**
     * @param  array<int, string>  $layoutGroupKeys
     */
    private function validateRosterSummaryPresets(mixed $presets, string $attribute, array $layoutGroupKeys): void
    {
        if (is_null($presets)) {
            return;
        }

        if (! is_array($presets)) {
            throw ValidationException::withMessages([
                $attribute => 'Roster summary presets must be an array.',
            ]);
        }

        $seenPresetKeys = [];

        foreach ($presets as $presetIndex => $preset) {
            if (! is_array($preset)) {
                throw ValidationException::withMessages([
                    "$attribute.$presetIndex" => 'Each roster summary preset must be an object.',
                ]);
            }

            $presetKey = $preset['key'] ?? null;

            if (blank($presetKey)) {
                throw ValidationException::withMessages([
                    "$attribute.$presetIndex.key" => 'Each roster summary preset requires a key.',
                ]);
            }

            if (in_array($presetKey, $seenPresetKeys, true)) {
                throw ValidationException::withMessages([
                    "$attribute.$presetIndex.key" => 'Roster summary preset keys must be unique.',
                ]);
            }

            $seenPresetKeys[] = $presetKey;

            $this->assertLocalizedValue($preset['label'] ?? null, "$attribute.$presetIndex.label");

            if (isset($preset['description'])) {
                $this->assertLocalizedValue($preset['description'], "$attribute.$presetIndex.description", false);
            }

            $requirements = $preset['requirements'] ?? null;

            if (! is_array($requirements) || $requirements === []) {
                throw ValidationException::withMessages([
                    "$attribute.$presetIndex.requirements" => 'Each roster summary preset requires at least one requirement.',
                ]);
            }

            $seenRequirementKeys = [];

            foreach ($requirements as $requirementIndex => $requirement) {
                if (! is_array($requirement)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex" => 'Each roster summary requirement must be an object.',
                    ]);
                }

                $source = $requirement['source'] ?? null;
                $sourceId = $requirement['source_id'] ?? null;
                $comparison = $requirement['comparison'] ?? null;
                $targetCount = $requirement['target_count'] ?? null;
                $scopeType = $requirement['scope_type'] ?? null;
                $scopeGroupKeys = $requirement['scope_group_keys'] ?? [];

                if (! in_array($source, ['character_classes', 'phantom_jobs'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.source" => 'Unsupported roster summary requirement source.',
                    ]);
                }

                if (! is_numeric($sourceId) || (int) $sourceId < 1) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.source_id" => 'Each roster summary requirement requires a valid source option.',
                    ]);
                }

                $sourceExists = match ($source) {
                    'character_classes' => CharacterClass::query()->whereKey((int) $sourceId)->exists(),
                    'phantom_jobs' => PhantomJob::query()->whereKey((int) $sourceId)->exists(),
                    default => false,
                };

                if (! $sourceExists) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.source_id" => 'The selected roster summary source option does not exist.',
                    ]);
                }

                if (! in_array($comparison, ['at_least', 'exactly', 'at_most'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.comparison" => 'Unsupported roster summary comparison mode.',
                    ]);
                }

                if (! is_numeric($targetCount) || (int) $targetCount < 1) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.target_count" => 'Each roster summary requirement needs a target count of at least 1.',
                    ]);
                }

                if (! in_array($scopeType, ['all_slots', 'slot_group', 'slot_group_set'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_type" => 'Unsupported roster summary scope type.',
                    ]);
                }

                if (! is_array($scopeGroupKeys)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => 'Roster summary scope group keys must be an array.',
                    ]);
                }

                $normalizedScopeGroupKeys = collect($scopeGroupKeys)
                    ->map(fn (mixed $key) => is_string($key) ? trim($key) : null)
                    ->filter(fn (?string $key) => filled($key))
                    ->values()
                    ->all();

                if (count($normalizedScopeGroupKeys) !== count($scopeGroupKeys)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => 'Roster summary scope group keys must only contain non-empty strings.',
                    ]);
                }

                if (collect($normalizedScopeGroupKeys)->duplicates()->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => 'Roster summary scope group keys must be unique.',
                    ]);
                }

                $unknownScopeGroupKeys = collect($normalizedScopeGroupKeys)
                    ->reject(fn (string $groupKey) => in_array($groupKey, $layoutGroupKeys, true))
                    ->values()
                    ->all();

                if ($unknownScopeGroupKeys !== []) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => 'Roster summary requirements can only reference groups defined in the activity layout.',
                    ]);
                }

                if ($scopeType === 'all_slots' && $normalizedScopeGroupKeys !== []) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => 'All-roster requirements cannot target specific groups.',
                    ]);
                }

                if ($scopeType === 'slot_group' && count($normalizedScopeGroupKeys) !== 1) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => 'Single-group requirements must target exactly one group.',
                    ]);
                }

                if ($scopeType === 'slot_group_set' && count($normalizedScopeGroupKeys) < 1) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.scope_group_keys" => 'Group-set requirements must target at least one group.',
                    ]);
                }

                $normalizedScopeGroupSetKey = collect($normalizedScopeGroupKeys)
                    ->sort()
                    ->values()
                    ->implode('|');

                $requirementKey = sprintf(
                    '%s:%s:%s:%s',
                    $source,
                    (int) $sourceId,
                    $scopeType,
                    $normalizedScopeGroupSetKey,
                );

                if (in_array($requirementKey, $seenRequirementKeys, true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$presetIndex.requirements.$requirementIndex.source_id" => 'Each roster summary requirement must be unique within its scope.',
                    ]);
                }

                $seenRequirementKeys[] = $requirementKey;
            }
        }
    }

    private function validateSchemaFields(mixed $fields, string $attribute, bool $supportsAnySelection = false): void
    {
        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                $attribute => 'Schema fields must be an array.',
            ]);
        }

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages([
                    "$attribute.$index" => 'Each schema field must be an object.',
                ]);
            }

            if (blank($field['key'] ?? null)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.key" => 'Each schema field requires a key.',
                ]);
            }

            if (! in_array($field['type'] ?? null, [
                'text',
                'textarea',
                'number',
                'boolean',
                'single_select',
                'multi_select',
                'url',
            ], true)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.type" => 'Unsupported schema field type.',
                ]);
            }

            $this->assertLocalizedValue($field['label'] ?? null, "$attribute.$index.label");

            if (isset($field['help_text'])) {
                $this->assertLocalizedValue($field['help_text'], "$attribute.$index.help_text", false);
            }

            $fieldType = (string) ($field['type'] ?? '');
            $acceptsAny = (bool) ($field['accepts_any'] ?? false);

            if ($acceptsAny) {
                if (! $supportsAnySelection || ! in_array($fieldType, ['single_select', 'multi_select'], true)) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.accepts_any" => 'Any selections are only supported on application select fields.',
                    ]);
                }

                $this->assertLocalizedValue($field['any_label'] ?? null, "$attribute.$index.any_label");
            }

            if (($field['source'] ?? null) === 'static_options') {
                if (! isset($field['options']) || ! is_array($field['options']) || $field['options'] === []) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.options" => 'Static option fields require at least one option.',
                    ]);
                }

                foreach ($field['options'] as $optionIndex => $option) {
                    if (! is_array($option) || blank($option['value'] ?? null)) {
                        throw ValidationException::withMessages([
                            "$attribute.$index.options.$optionIndex" => 'Each static option requires a value.',
                        ]);
                    }

                    $this->assertLocalizedValue($option['label'] ?? null, "$attribute.$index.options.$optionIndex.label");
                }
            }
        }
    }

    private function assertLocalizedValue(mixed $value, string $attribute, bool $requireEnglish = true): void
    {
        if (! is_array($value) || $value === []) {
            throw ValidationException::withMessages([
                $attribute => 'This field must be a localized object.',
            ]);
        }

        if ($requireEnglish && (! array_key_exists('en', $value) || blank($value['en']))) {
            throw ValidationException::withMessages([
                "$attribute.en" => 'An English translation is required.',
            ]);
        }

        foreach ($value as $locale => $translation) {
            if (! is_string($locale) || (! is_string($translation) && ! is_null($translation))) {
                throw ValidationException::withMessages([
                    $attribute => 'Localized values must be keyed by locale and contain strings.',
                ]);
            }
        }
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
                'url',
            ],
            'supportedOptionSources' => [
                'character_classes',
                'phantom_jobs',
                'raid_positions',
                'static_options',
            ],
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
