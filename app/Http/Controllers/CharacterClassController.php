<?php

namespace App\Http\Controllers;

use App\Models\CharacterClass;
use App\Services\AuditLogger;
use App\Services\ManagedImageStorage;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class CharacterClassController extends Controller
{
    private const IMAGE_DIRECTORY = 'character-classes';

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage,
        private readonly AuditLogger $auditLogger
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.character-data');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $validated['icon_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['icon_url'] ?? null,
            'icon_url',
            self::IMAGE_DIRECTORY
        );
        $validated['flaticon_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['flaticon_url'] ?? null,
            'flaticon_url',
            self::IMAGE_DIRECTORY
        );

        $characterClass = CharacterClass::create($validated);

        $this->auditLogger->log(
            action: 'admin.character_class.created',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.character_class.created',
            actor: auth()->user(),
            subject: $characterClass,
            metadata: $this->characterClassSnapshot($characterClass),
        );

        return redirect()->back()->with('success', 'character_class_created');
    }

    public function show(CharacterClass $characterClass): RedirectResponse
    {
        return redirect()->route('admin.character-data');
    }

    public function update(Request $request, CharacterClass $characterClass): RedirectResponse
    {
        $originalValues = $this->characterClassSnapshot($characterClass);
        $validated = $request->validate($this->rules($characterClass->id));

        $validated['icon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $characterClass->icon_url,
            newUrl: $validated['icon_url'] ?? null,
            field: 'icon_url',
            directory: self::IMAGE_DIRECTORY
        );
        $validated['flaticon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $characterClass->flaticon_url,
            newUrl: $validated['flaticon_url'] ?? null,
            field: 'flaticon_url',
            directory: self::IMAGE_DIRECTORY
        );

        $characterClass->update($validated);

        $updatedValues = $this->characterClassSnapshot($characterClass->fresh());
        $changes = $this->buildChanges($originalValues, $updatedValues);

        if ($changes !== []) {
            $this->auditLogger->log(
                action: 'admin.character_class.updated',
                severity: AuditSeverity::CRITICAL,
                scopeType: AuditScope::ADMIN,
                scopeId: null,
                message: 'audit_log.events.admin.character_class.updated',
                actor: auth()->user(),
                subject: $characterClass,
                metadata: [
                    'changed_fields' => array_keys($changes),
                    'changes' => $changes,
                ],
            );
        }

        return redirect()->back()->with('success', 'character_class_updated');
    }

    public function destroy(CharacterClass $characterClass): RedirectResponse
    {
        $snapshot = $this->characterClassSnapshot($characterClass);
        $this->managedImageStorage->deleteManagedImage($characterClass->icon_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($characterClass->flaticon_url, self::IMAGE_DIRECTORY);

        $characterClass->delete();

        $this->auditLogger->log(
            action: 'admin.character_class.deleted',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.character_class.deleted',
            actor: auth()->user(),
            subject: [
                'subject_type' => CharacterClass::class,
                'subject_id' => $snapshot['id'],
            ],
            metadata: $snapshot,
        );

        return redirect()->back()->with('success', 'character_class_deleted');
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function rules(?int $characterClassId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('character_classes', 'name')->ignore($characterClassId),
            ],
            'shorthand' => [
                'required',
                'string',
                'max:20',
                Rule::unique('character_classes', 'shorthand')->ignore($characterClassId),
            ],
            'icon_url' => ['nullable', 'url', 'max:500'],
            'flaticon_url' => ['nullable', 'url', 'max:500'],
            'role' => ['required', Rule::in(CharacterClass::ROLES)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function characterClassSnapshot(CharacterClass $characterClass): array
    {
        return [
            'id' => $characterClass->id,
            'name' => $characterClass->name,
            'shorthand' => $characterClass->shorthand,
            'icon_url' => $characterClass->icon_url,
            'flaticon_url' => $characterClass->flaticon_url,
            'role' => $characterClass->role,
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
}
