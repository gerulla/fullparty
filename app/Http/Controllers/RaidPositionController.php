<?php

namespace App\Http\Controllers;

use App\Models\RaidPosition;
use App\Services\AuditLogger;
use App\Services\ManagedImageStorage;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RaidPositionController extends Controller
{
    private const IMAGE_DIRECTORY = 'raid-positions';

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage,
        private readonly AuditLogger $auditLogger
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $validated = $request->validate($this->rules());
        $validated['icon_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['icon_url'] ?? null,
            'icon_url',
            self::IMAGE_DIRECTORY
        );

        $raidPosition = RaidPosition::create($validated);

        $this->auditLogger->log(
            action: 'admin.raid_position.created',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.raid_position.created',
            actor: auth()->user(),
            subject: $raidPosition,
            metadata: $this->raidPositionSnapshot($raidPosition),
        );

        return redirect()->back()->with('success', 'raid_position_created');
    }

    public function update(Request $request, RaidPosition $raidPosition): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $originalValues = $this->raidPositionSnapshot($raidPosition);
        $validated = $request->validate($this->rules($raidPosition->id));
        $validated['icon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $raidPosition->icon_url,
            newUrl: $validated['icon_url'] ?? null,
            field: 'icon_url',
            directory: self::IMAGE_DIRECTORY
        );

        $raidPosition->update($validated);

        $updatedValues = $this->raidPositionSnapshot($raidPosition->fresh());
        $changes = $this->buildChanges($originalValues, $updatedValues);

        if ($changes !== []) {
            $this->auditLogger->log(
                action: 'admin.raid_position.updated',
                severity: AuditSeverity::CRITICAL,
                scopeType: AuditScope::ADMIN,
                scopeId: null,
                message: 'audit_log.events.admin.raid_position.updated',
                actor: auth()->user(),
                subject: $raidPosition,
                metadata: [
                    'changed_fields' => array_keys($changes),
                    'changes' => $changes,
                ],
            );
        }

        return redirect()->back()->with('success', 'raid_position_updated');
    }

    public function destroy(RaidPosition $raidPosition): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $snapshot = $this->raidPositionSnapshot($raidPosition);
        $this->managedImageStorage->deleteManagedImage($raidPosition->icon_url, self::IMAGE_DIRECTORY);

        $raidPosition->delete();

        $this->auditLogger->log(
            action: 'admin.raid_position.deleted',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.raid_position.deleted',
            actor: auth()->user(),
            subject: [
                'subject_type' => RaidPosition::class,
                'subject_id' => $snapshot['id'],
            ],
            metadata: $snapshot,
        );

        return redirect()->back()->with('success', 'raid_position_deleted');
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    private function rules(?int $raidPositionId = null): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9_:-]+$/',
                Rule::unique('raid_positions', 'key')->ignore($raidPositionId),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('raid_positions', 'name')->ignore($raidPositionId),
            ],
            'icon_url' => ['nullable', 'url', 'max:500'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function raidPositionSnapshot(RaidPosition $raidPosition): array
    {
        return [
            'id' => $raidPosition->id,
            'key' => $raidPosition->key,
            'name' => $raidPosition->name,
            'icon_url' => $raidPosition->icon_url,
            'sort_order' => $raidPosition->sort_order,
            'is_active' => $raidPosition->is_active,
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

    private function authorizeAdminAccess(): void
    {
        if (! auth()->user()?->is_admin) {
            abort(403);
        }
    }
}
