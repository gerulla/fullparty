<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Group;
use Inertia\Inertia;
use Inertia\Response;

class GroupAuditLogController extends Controller
{
    public function index(Group $group): Response
    {
        $group->load([
            'owner',
            'memberships.user',
        ]);

        $this->authorizeModeratorAccess($group);

        $auditLogs = AuditLog::query()
            ->with(['actor', 'subject'])
            ->where('scope_type', 'group')
            ->where('scope_id', $group->id)
            ->latest('created_at')
            ->get();

        return Inertia::render('Dashboard/Groups/AuditLog/Index', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'profile_picture_url' => $group->profile_picture_url,
                'discord_invite_url' => $group->discord_invite_url,
                'datacenter' => $group->datacenter,
                'is_public' => $group->is_public,
                'is_visible' => $group->is_visible,
                'slug' => $group->slug,
                'owner' => [
                    'id' => $group->owner?->id,
                    'name' => $group->owner?->name,
                    'avatar_url' => $group->owner?->avatar_url,
                ],
                'current_user_role' => $group->memberships
                    ->firstWhere('user_id', auth()->id())
                    ?->role,
                'permissions' => [
                    'can_manage_group' => $group->isOwnedBy(auth()->id()),
                    'can_manage_members' => $group->hasModeratorAccess(auth()->id()),
                    'can_manage_roles' => $group->isOwnedBy(auth()->id()),
                    'can_view_bans' => $group->hasModeratorAccess(auth()->id()),
                ],
            ],
            'auditLogs' => $auditLogs
                ->map(fn (AuditLog $auditLog) => [
                    'id' => $auditLog->id,
                    'action' => $auditLog->action,
                    'severity' => $auditLog->severity,
                    'actor' => [
                        'id' => $auditLog->actor?->id,
                        'name' => $auditLog->actor?->name ?? __('audit_log.defaults.system'),
                        'avatar_url' => $auditLog->actor?->avatar_url,
                        'is_system' => $auditLog->actor === null,
                    ],
                    'subject' => [
                        'type' => $auditLog->subject_type,
						'id' => $auditLog->subject?->id,
						'name' => $auditLog->subject?->name ?? __('audit_log.defaults.system'),
						'avatar_url' => $auditLog->subject?->avatar_url,
						'is_system' => $auditLog->subject === null,
					],
                    'title' => __($auditLog->message),
                    'summary' => $this->resolveSummary($auditLog),
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
                'users' => $group->memberships
                    ->map(fn ($membership) => $membership->user)
                    ->merge($auditLogs->pluck('actor')->filter())
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
                            'label' => __('audit_log.defaults.system'),
                        ]);
                    })
                    ->values(),
            ],
        ]);
    }

    private function resolveSummary(AuditLog $auditLog): string
    {
        if (is_string($summary = __('audit_log.activity.'.$auditLog->action)) && $summary !== 'audit_log.activity.'.$auditLog->action) {
            return $summary;
        }

        $metadata = $auditLog->metadata ?? [];

        if (!is_array($metadata) || $metadata === []) {
            return __('audit_log.defaults.no_metadata');
        }

        $details = $this->resolveMetadataDetails($metadata);

        return $details !== []
            ? implode(' | ', $details)
            : __('audit_log.defaults.no_metadata');
    }

    /**
     * @param  array<string, mixed>  $metadata
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

    /**
     * @param  AuditLog  $auditLog
     * @return string
     */
    private function buildSearchText(AuditLog $auditLog): string
    {
        $metadata = is_array($auditLog->metadata) ? $auditLog->metadata : [];
        $details = implode(' ', $this->resolveMetadataDetails($metadata));
        $changes = implode(' ', collect($this->resolveChanges($metadata['changes'] ?? null))
            ->flatMap(fn (array $change) => [$change['label'], $change['old'], $change['new']])
            ->all());

        return implode(' ', array_filter([
            __($auditLog->message),
            $this->resolveSummary($auditLog),
            $auditLog->action,
            $auditLog->severity,
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

    private function authorizeModeratorAccess(Group $group): void
    {
        if (!$group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }
}
