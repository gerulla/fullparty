<?php

namespace App\Http\Controllers;

use App\Models\CharacterFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCharacterController extends Controller
{
	/**
	 * Store a newly created field definition.
	 */
	public function storeDefinition(Request $request)
	{
		$validated = $request->validate([
			'name' => ['required', 'string', 'max:255'],
			'type' => ['required', 'in:text,number,date,textarea,select,checkbox'],
			'description' => ['nullable', 'string'],
			'validation_rules' => ['nullable', 'array'],
			'is_active' => ['boolean'],
		]);

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
		$validated = $request->validate([
			'name' => ['required', 'string', 'max:255'],
			'type' => ['required', 'in:text,number,date,textarea,select,checkbox'],
			'description' => ['nullable', 'string'],
			'validation_rules' => ['nullable', 'array'],
			'is_active' => ['boolean'],
		]);

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
}
