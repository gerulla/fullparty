<?php

namespace App\Http\Controllers;

use App\Models\CharacterFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCharacterController extends Controller
{
	private const DISPLAY_CONTEXTS = ['profile', 'account', 'admin'];

	private const SOURCE_TYPES = ['user', 'system', 'hybrid'];

	/**
	 * Store a newly created field definition.
	 */
	public function storeDefinition(Request $request)
	{
		$validated = $this->validateDefinition($request);

		// Generate slug from name
		$validated['slug'] = Str::slug($validated['name']);

		// Get the next sort order
		$maxOrder = CharacterFieldDefinition::max('sort_order') ?? 0;
		$validated['sort_order'] = $maxOrder + 1;

		$definition = CharacterFieldDefinition::create($validated);

		return redirect()->back()->with('success', 'field_definition_created');
	}

	/**
	 * Update the specified field definition.
	 */
	public function updateDefinition(Request $request, CharacterFieldDefinition $definition)
	{
		$validated = $this->validateDefinition($request);

		// Regenerate slug if name changed
		if ($validated['name'] !== $definition->name) {
			$validated['slug'] = Str::slug($validated['name']);
		}

		$definition->update($validated);

		return redirect()->back()->with('success', 'field_definition_updated');
	}

	/**
	 * Remove the specified field definition.
	 */
	public function destroyDefinition(CharacterFieldDefinition $definition)
	{
		$definition->delete();

		return redirect()->back()->with('success', 'field_definition_deleted');
	}

	/**
	 * Update the sort order of field definitions.
	 */
	public function updateOrder(Request $request)
	{
		$validated = $request->validate([
			'order' => ['required', 'array'],
			'order.*' => ['required', 'exists:character_field_definitions,id'],
		]);

		foreach ($validated['order'] as $index => $id) {
			CharacterFieldDefinition::where('id', $id)->update(['sort_order' => $index]);
		}

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
}
