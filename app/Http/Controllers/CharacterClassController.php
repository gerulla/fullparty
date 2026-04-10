<?php

namespace App\Http\Controllers;

use App\Models\CharacterClass;
use App\Services\ManagedImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class CharacterClassController extends Controller
{
    private const IMAGE_DIRECTORY = 'character-classes';

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage
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

        CharacterClass::create($validated);

        return redirect()->back()->with('success', 'character_class_created');
    }

    public function show(CharacterClass $characterClass): RedirectResponse
    {
        return redirect()->route('admin.character-data');
    }

    public function update(Request $request, CharacterClass $characterClass): RedirectResponse
    {
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

        return redirect()->back()->with('success', 'character_class_updated');
    }

    public function destroy(CharacterClass $characterClass): RedirectResponse
    {
        $this->managedImageStorage->deleteManagedImage($characterClass->icon_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($characterClass->flaticon_url, self::IMAGE_DIRECTORY);

        $characterClass->delete();

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
}
