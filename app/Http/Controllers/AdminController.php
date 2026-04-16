<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\CharacterFieldDefinition;
use App\Models\PhantomJob;
use App\Models\Group;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    /**
     * Display the consolidated character data admin page.
     */
    public function characterData(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/CharacterData', [
            'definitions' => CharacterFieldDefinition::ordered()->get(),
            'characterClasses' => CharacterClass::query()
                ->orderBy('role')
                ->orderBy('name')
                ->get(),
            'phantomJobs' => PhantomJob::query()
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function auditLog(): Response
    {
        $this->authorizeAdminAccess();

        $auditLogs = AuditLog::query()
            ->with(['actor', 'subject'])
            ->latest('created_at')
            ->get();
        $scopeEntities = $this->resolveScopeEntities($auditLogs);

        return Inertia::render('Admin/AuditLog', [
            'auditLogs' => $auditLogs
                ->map(fn (AuditLog $auditLog) => [
                    'id' => $auditLog->id,
                    'action' => $auditLog->action,
                    'severity' => $auditLog->severity,
                    'scope' => [
                        'type' => $auditLog->scope_type,
                        'id' => $auditLog->scope_id,
                        'label' => $scopeEntities[$auditLog->scope_type][$auditLog->scope_id] ?? null,
                    ],
                    'actor' => [
                        'id' => $auditLog->actor?->id,
                        'name' => $auditLog->actor?->name ?? 'System',
                        'avatar_url' => $auditLog->actor?->avatar_url,
                        'is_system' => $auditLog->actor === null,
                    ],
                    'subject' => [
                        'type' => $auditLog->subject_type,
                        'id' => $auditLog->subject?->id,
                        'name' => $this->resolveSubjectName($auditLog),
                        'avatar_url' => $auditLog->subject?->avatar_url,
                        'is_system' => $auditLog->subject === null,
                    ],
                    'title' => $auditLog->message,
                    'changes' => $this->resolveChanges($auditLog->metadata['changes'] ?? null),
                    'details' => $this->resolveMetadataDetails($auditLog->metadata ?? []),
                    'search_text' => $this->buildSearchText($auditLog),
                    'created_at' => $auditLog->created_at?->toIso8601String(),
                ]),
            'filters' => [
                'actions' => $auditLogs
                    ->unique('action')
                    ->map(fn (AuditLog $auditLog) => [
                        'value' => $auditLog->action,
                        'label' => $auditLog->message,
                    ])
                    ->sortBy('label')
                    ->values(),
                'severities' => $auditLogs
                    ->pluck('severity')
                    ->unique()
                    ->sort()
                    ->values()
                    ->map(fn (string $severity) => [
                        'value' => $severity,
                        'label' => 'audit_log.severities.'.$severity,
                    ]),
                'users' => $auditLogs
                    ->pluck('actor')
                    ->filter()
                    ->unique('id')
                    ->sortBy('name')
                    ->values()
                    ->map(fn ($user) => [
                        'value' => (string) $user->id,
                        'label' => $user->name,
                    ])
                    ->when($auditLogs->contains(fn (AuditLog $auditLog) => $auditLog->actor === null), function ($users) {
                        return $users->prepend([
                            'value' => '__system__',
                            'label' => 'audit_log.defaults.system',
                        ]);
                    })
                    ->values(),
                'groups' => collect($scopeEntities['group'] ?? [])
                    ->map(fn (string $name, int $id) => [
                        'value' => (string) $id,
                        'label' => $name,
                    ])
                    ->sortBy('label')
                    ->values(),
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditLog>  $auditLogs
     * @return array<string, array<int, string>>
     */
    private function resolveScopeEntities($auditLogs): array
    {
        $groupIds = $auditLogs
            ->where('scope_type', 'group')
            ->pluck('scope_id')
            ->filter()
            ->unique()
            ->all();

        $userIds = $auditLogs
            ->where('scope_type', 'user')
            ->pluck('scope_id')
            ->filter()
            ->unique()
            ->all();

        $characterIds = $auditLogs
            ->where('scope_type', 'character')
            ->pluck('scope_id')
            ->filter()
            ->unique()
            ->all();

        return [
            'group' => Group::query()
                ->whereIn('id', $groupIds)
                ->get(['id', 'name'])
                ->pluck('name', 'id')
                ->all(),
            'user' => User::query()
                ->whereIn('id', $userIds)
                ->get(['id', 'name'])
                ->pluck('name', 'id')
                ->all(),
            'character' => Character::query()
                ->whereIn('id', $characterIds)
                ->get(['id', 'name'])
                ->pluck('name', 'id')
                ->all(),
        ];
    }

    private function authorizeAdminAccess(): void
    {
        if (!auth()->user()?->is_admin) {
            abort(403);
        }
    }

    /**
     * @return array<int, array{label: string, old: string, new: string}>
     */
    private function resolveChanges(mixed $changes): array
    {
        if (!is_array($changes) || $changes === []) {
            return [];
        }

        return collect($changes)
            ->map(function ($change, $field) {
                if (!is_array($change) || !array_key_exists('old', $change) || !array_key_exists('new', $change)) {
                    return null;
                }

                return [
                    'label' => $this->resolveMetadataLabel((string) $field),
                    'old' => $this->stringifyMetadataValue($change['old']),
                    'new' => $this->stringifyMetadataValue($change['new']),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    private function resolveMetadataDetails(array $metadata): array
    {
        $remainingMetadata = $metadata;
        unset($remainingMetadata['changes']);

        return collect($remainingMetadata)
            ->map(function ($value, $key) {
                if (is_array($value)) {
                    $value = implode(', ', array_map(fn ($item) => $this->stringifyMetadataValue($item), $value));
                }

                if ($value === null || $value === '') {
                    return null;
                }

                return $this->resolveMetadataLabel((string) $key).': '.$this->stringifyMetadataValue($value);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function buildSearchText(AuditLog $auditLog): string
    {
        $metadata = is_array($auditLog->metadata) ? $auditLog->metadata : [];
        $details = implode(' ', $this->resolveMetadataDetails($metadata));
        $changes = implode(' ', collect($this->resolveChanges($metadata['changes'] ?? null))
            ->flatMap(fn (array $change) => [$change['label'], $change['old'], $change['new']])
            ->all());

        return implode(' ', array_filter([
            $auditLog->message,
            $auditLog->action,
            $auditLog->severity,
            $auditLog->scope_type,
            $auditLog->actor?->name,
            $auditLog->subject?->name,
            $details,
            $changes,
        ]));
    }

    private function resolveMetadataLabel(string $key): string
    {
        $translationKey = 'audit_log.fields.'.$key;
        $translated = __($translationKey);

        return $translated !== $translationKey
            ? $translated
            : str_replace('_', ' ', $key);
    }

    private function stringifyMetadataValue(mixed $value): string
    {
        if ($value === null) {
            return __('audit_log.defaults.empty');
        }

        if (is_bool($value)) {
            return $value
                ? __('audit_log.defaults.true')
                : __('audit_log.defaults.false');
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => $this->stringifyMetadataValue($item), $value));
        }

        $stringValue = trim((string) $value);

        return $stringValue !== '' ? $stringValue : __('audit_log.defaults.empty');
    }

    private function resolveSubjectName(AuditLog $auditLog): string
    {
        $subjectName = $auditLog->subject?->name;

        if (filled($subjectName)) {
            return $subjectName;
        }

        $metadata = is_array($auditLog->metadata) ? $auditLog->metadata : [];

        if (
            $auditLog->subject_type === \App\Models\ActivityType::class
            && filled($metadata['activity_type_name'] ?? null)
        ) {
            return (string) $metadata['activity_type_name'];
        }

        return 'System';
    }
}
