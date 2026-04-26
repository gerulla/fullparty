<?php

namespace App\Http\Controllers;

use App\Models\CharacterFieldDefinition;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCharacterController extends Controller
{
	private const DISPLAY_CONTEXTS = ['profile', 'account', 'admin'];

	private const SOURCE_TYPES = ['user', 'system', 'hybrid'];

	public function __construct(
		private readonly AuditLogger $auditLogger
	) {}

	/**
	 * Store a newly created field definition.
	 */
	public function storeDefinition(Request $request)
	{
		$this->authorizeAdminAccess();

		$validated = $this->validateDefinition($request);

		// Generate slug from name
		$validated['slug'] = Str::slug($validated['name']);

		// Get the next sort order
		$maxOrder = CharacterFieldDefinition::max('sort_order') ?? 0;
		$validated['sort_order'] = $maxOrder + 1;

		$definition = CharacterFieldDefinition::create($validated);

		$this->auditLogger->log(
			action: 'admin.field_definition.created',
			severity: AuditSeverity::CRITICAL,
			scopeType: AuditScope::ADMIN,
			scopeId: null,
			message: 'audit_log.events.admin.field_definition.created',
			actor: auth()->user(),
			subject: $definition,
			metadata: $this->definitionSnapshot($definition),
		);

		return redirect()->back()->with('success', 'field_definition_created');
	}

	/**
	 * Update the specified field definition.
	 */
	public function updateDefinition(Request $request, CharacterFieldDefinition $definition)
	{
		$this->authorizeAdminAccess();

		$validated = $this->validateDefinition($request);
		$originalValues = $this->definitionSnapshot($definition);

		// Regenerate slug if name changed
		if ($validated['name'] !== $definition->name) {
			$validated['slug'] = Str::slug($validated['name']);
		}

		$definition->update($validated);

		$updatedValues = $this->definitionSnapshot($definition->fresh());
		$changes = $this->buildChanges($originalValues, $updatedValues);

		if ($changes !== []) {
			$this->auditLogger->log(
				action: 'admin.field_definition.updated',
				severity: AuditSeverity::CRITICAL,
				scopeType: AuditScope::ADMIN,
				scopeId: null,
				message: 'audit_log.events.admin.field_definition.updated',
				actor: auth()->user(),
				subject: $definition,
				metadata: [
					'changed_fields' => array_keys($changes),
					'changes' => $changes,
				],
			);
		}

		return redirect()->back()->with('success', 'field_definition_updated');
	}

	/**
	 * Remove the specified field definition.
	 */
	public function destroyDefinition(CharacterFieldDefinition $definition)
	{
		$this->authorizeAdminAccess();

		$snapshot = $this->definitionSnapshot($definition);
		$definition->delete();

		$this->auditLogger->log(
			action: 'admin.field_definition.deleted',
			severity: AuditSeverity::CRITICAL,
			scopeType: AuditScope::ADMIN,
			scopeId: null,
			message: 'audit_log.events.admin.field_definition.deleted',
			actor: auth()->user(),
			subject: [
				'subject_type' => CharacterFieldDefinition::class,
				'subject_id' => $snapshot['id'],
			],
			metadata: $snapshot,
		);

		return redirect()->back()->with('success', 'field_definition_deleted');
	}

	/**
	 * Update the sort order of field definitions.
	 */
	public function updateOrder(Request $request)
	{
		$this->authorizeAdminAccess();

		$validated = $request->validate([
			'order' => ['required', 'array'],
			'order.*' => ['required', 'exists:character_field_definitions,id'],
		]);

		foreach ($validated['order'] as $index => $id) {
			CharacterFieldDefinition::where('id', $id)->update(['sort_order' => $index]);
		}

		$this->auditLogger->log(
			action: 'admin.field_definition.reordered',
			severity: AuditSeverity::CRITICAL,
			scopeType: AuditScope::ADMIN,
			scopeId: null,
			message: 'audit_log.events.admin.field_definition.reordered',
			actor: auth()->user(),
			metadata: [
				'ordered_ids' => array_values($validated['order']),
			],
		);

		return redirect()->back()->with('success', 'field_order_updated');
	}

	private function validateDefinition(Request $request): array
	{
		return $request->validate([
			'name' => ['required', 'string', 'max:255'],
			'type' => ['required', 'in:text,number,date,textarea,select,checkbox'],
			'description' => ['nullable', 'string'],
			'group' => ['required', 'string', 'max:255'],
			'display_contexts' => ['nullable', 'array'],
			'display_contexts.*' => ['required', 'in:' . implode(',', self::DISPLAY_CONTEXTS)],
			'source_type' => ['required', 'in:' . implode(',', self::SOURCE_TYPES)],
			'is_editable' => ['boolean'],
			'is_visible' => ['boolean'],
			'tags' => ['nullable', 'array'],
			'tags.*' => ['required', 'string', 'max:255'],
			'validation_rules' => ['nullable', 'array'],
			'is_active' => ['boolean'],
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function definitionSnapshot(CharacterFieldDefinition $definition): array
	{
		return [
			'id' => $definition->id,
			'name' => $definition->name,
			'slug' => $definition->slug,
			'type' => $definition->type,
			'description' => $definition->description,
			'group' => $definition->group,
			'display_contexts' => $definition->display_contexts ?? [],
			'source_type' => $definition->source_type,
			'is_editable' => $definition->is_editable,
			'is_visible' => $definition->is_visible,
			'tags' => $definition->tags ?? [],
			'validation_rules' => $definition->validation_rules ?? [],
			'is_active' => $definition->is_active,
			'sort_order' => $definition->sort_order,
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
		if (!auth()->user()?->is_admin) {
			abort(403);
		}
	}
}
