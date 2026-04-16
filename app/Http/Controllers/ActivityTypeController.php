<?php

namespace App\Http\Controllers;

use App\Models\ActivityType;
use App\Models\ActivityTypeVersion;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ActivityTypeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    public function index(): Response
    {
        $this->authorizeAdminAccess();

        $activityTypes = ActivityType::query()
            ->with(['creator:id,name', 'currentPublishedVersion.publisher:id,name', 'versions.publisher:id,name'])
            ->latest('updated_at')
            ->get();

        return Inertia::render('Admin/ActivityTypes', [
            'activityTypes' => $activityTypes->map(fn (ActivityType $activityType) => $this->transformActivityType($activityType)),
            'schemaReference' => [
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
                    'static_options',
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/ActivityTypesCreate', [
            'schemaReference' => $this->schemaReference(),
        ]);
    }

    public function edit(ActivityType $activityType): Response
    {
        $this->authorizeAdminAccess();

        $activityType->load(['creator:id,name', 'currentPublishedVersion.publisher:id,name', 'versions.publisher:id,name']);

        return Inertia::render('Admin/ActivityTypesEdit', [
            'activityType' => $this->transformActivityType($activityType),
            'schemaReference' => $this->schemaReference(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $validated = $request->validate($this->rules());
        $this->validateDraftSchema($validated);

        $activityType = ActivityType::create([
            ...$validated,
            'created_by_user_id' => auth()->id(),
        ]);

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
        $validated = $request->validate($this->rules($activityType->id));
        $this->validateDraftSchema($validated);

        $activityType->update($validated);

        $updatedValues = $this->activityTypeSnapshot($activityType->fresh());
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
            'draft_layout_schema' => $activityType->draft_layout_schema,
            'draft_slot_schema' => $activityType->draft_slot_schema,
            'draft_application_schema' => $activityType->draft_application_schema,
            'draft_progress_schema' => $activityType->draft_progress_schema,
        ];

        $this->validateDraftSchema($draftPayload);

        $version = DB::transaction(function () use ($activityType) {
            $nextVersion = ((int) $activityType->versions()->max('version')) + 1;

            $version = $activityType->versions()->create([
                'version' => $nextVersion,
                'name' => $activityType->draft_name,
                'description' => $activityType->draft_description,
                'layout_schema' => $activityType->draft_layout_schema,
                'slot_schema' => $activityType->draft_slot_schema,
                'application_schema' => $activityType->draft_application_schema,
                'progress_schema' => $activityType->draft_progress_schema,
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

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
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
            'draft_layout_schema' => ['required', 'array'],
            'draft_slot_schema' => ['required', 'array'],
            'draft_application_schema' => ['required', 'array'],
            'draft_progress_schema' => ['required', 'array'],
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
        $progressSchema = $validated['draft_progress_schema'] ?? null;

        if (!is_array($name) || !array_key_exists('en', $name) || blank($name['en'])) {
            throw ValidationException::withMessages([
                'draft_name.en' => 'An English activity type name is required.',
            ]);
        }

        if (!is_array($layoutSchema) || !isset($layoutSchema['groups']) || !is_array($layoutSchema['groups']) || $layoutSchema['groups'] === []) {
            throw ValidationException::withMessages([
                'draft_layout_schema.groups' => 'At least one slot group is required.',
            ]);
        }

        foreach ($layoutSchema['groups'] as $index => $group) {
            if (!is_array($group)) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index" => 'Each slot group must be an object.',
                ]);
            }

            if (blank($group['key'] ?? null) || blank($group['size'] ?? null)) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index" => 'Each slot group requires a key and size.',
                ]);
            }

            if (!is_numeric($group['size']) || (int) $group['size'] < 1) {
                throw ValidationException::withMessages([
                    "draft_layout_schema.groups.$index.size" => 'Each slot group size must be at least 1.',
                ]);
            }

            $this->assertLocalizedValue($group['label'] ?? null, "draft_layout_schema.groups.$index.label");
        }

        $this->validateSchemaFields($slotSchema, 'draft_slot_schema');
        $this->validateSchemaFields($applicationSchema, 'draft_application_schema');
        $this->validateProgressSchema($progressSchema, 'draft_progress_schema');
    }

    private function validateProgressSchema(mixed $progressSchema, string $attribute): void
    {
        if (!is_array($progressSchema)) {
            throw ValidationException::withMessages([
                $attribute => 'Progress schema must be an object.',
            ]);
        }

        $milestones = $progressSchema['milestones'] ?? null;

        if (!is_array($milestones)) {
            throw ValidationException::withMessages([
                "$attribute.milestones" => 'Progress milestones must be an array.',
            ]);
        }

        foreach ($milestones as $index => $milestone) {
            if (!is_array($milestone)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index" => 'Each milestone must be an object.',
                ]);
            }

            if (blank($milestone['key'] ?? null)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.key" => 'Each milestone requires a key.',
                ]);
            }

            if (!is_numeric($milestone['order'] ?? null) || (int) $milestone['order'] < 1) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.order" => 'Each milestone requires a valid order.',
                ]);
            }

            $matcher = $milestone['fflogs_matcher'] ?? null;

            if (!is_array($matcher)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher" => 'Each milestone requires an FF Logs matcher.',
                ]);
            }

            $matcherType = $matcher['type'] ?? null;

            if (!in_array($matcherType, ['encounter', 'phase'], true)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.type" => 'Unsupported FF Logs matcher type.',
                ]);
            }

            if (!is_numeric($matcher['encounter_id'] ?? null) || (int) $matcher['encounter_id'] < 1) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.encounter_id" => 'Each milestone requires a valid FF Logs encounter ID.',
                ]);
            }

            if ($matcherType === 'phase' && (!is_numeric($matcher['phase_id'] ?? null) || (int) $matcher['phase_id'] < 1)) {
                throw ValidationException::withMessages([
                    "$attribute.milestones.$index.fflogs_matcher.phase_id" => 'Phase milestones require a valid FF Logs phase ID.',
                ]);
            }

            $this->assertLocalizedValue($milestone['label'] ?? null, "$attribute.milestones.$index.label");
        }
    }

    private function validateSchemaFields(mixed $fields, string $attribute): void
    {
        if (!is_array($fields)) {
            throw ValidationException::withMessages([
                $attribute => 'Schema fields must be an array.',
            ]);
        }

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                throw ValidationException::withMessages([
                    "$attribute.$index" => 'Each schema field must be an object.',
                ]);
            }

            if (blank($field['key'] ?? null)) {
                throw ValidationException::withMessages([
                    "$attribute.$index.key" => 'Each schema field requires a key.',
                ]);
            }

            if (!in_array($field['type'] ?? null, [
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

            if (($field['source'] ?? null) === 'static_options') {
                if (!isset($field['options']) || !is_array($field['options']) || $field['options'] === []) {
                    throw ValidationException::withMessages([
                        "$attribute.$index.options" => 'Static option fields require at least one option.',
                    ]);
                }

                foreach ($field['options'] as $optionIndex => $option) {
                    if (!is_array($option) || blank($option['value'] ?? null)) {
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
        if (!is_array($value) || $value === []) {
            throw ValidationException::withMessages([
                $attribute => 'This field must be a localized object.',
            ]);
        }

        if ($requireEnglish && (!array_key_exists('en', $value) || blank($value['en']))) {
            throw ValidationException::withMessages([
                "$attribute.en" => 'An English translation is required.',
            ]);
        }

        foreach ($value as $locale => $translation) {
            if (!is_string($locale) || (!is_string($translation) && !is_null($translation))) {
                throw ValidationException::withMessages([
                    $attribute => 'Localized values must be keyed by locale and contain strings.',
                ]);
            }
        }
    }

    private function authorizeAdminAccess(): void
    {
        if (!auth()->user()?->is_admin) {
            abort(403);
        }
    }

    /**
     * @return array<string, array<int, string>>
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
                'static_options',
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
            'draft_layout_schema' => $activityType->draft_layout_schema,
            'draft_slot_schema' => $activityType->draft_slot_schema,
            'draft_application_schema' => $activityType->draft_application_schema,
            'draft_progress_schema' => $activityType->draft_progress_schema,
            'created_by' => $activityType->creator?->name,
            'current_published_version' => $currentVersion ? [
                'id' => $currentVersion->id,
                'version' => $currentVersion->version,
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
            'draft_layout_schema' => $activityType->draft_layout_schema,
            'draft_slot_schema' => $activityType->draft_slot_schema,
            'draft_application_schema' => $activityType->draft_application_schema,
            'draft_progress_schema' => $activityType->draft_progress_schema,
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
