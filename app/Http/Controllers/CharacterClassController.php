<?php

namespace App\Http\Controllers;

use App\Models\CharacterClass;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CharacterClassController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.character-data');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $validated['icon_url'] = $this->downloadImageIfPresent($validated['icon_url'] ?? null, 'icon_url');
        $validated['flaticon_url'] = $this->downloadImageIfPresent($validated['flaticon_url'] ?? null, 'flaticon_url');

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

        $validated['icon_url'] = $this->replaceImageIfPresent(
            currentUrl: $characterClass->icon_url,
            newUrl: $validated['icon_url'] ?? null,
            field: 'icon_url'
        );
        $validated['flaticon_url'] = $this->replaceImageIfPresent(
            currentUrl: $characterClass->flaticon_url,
            newUrl: $validated['flaticon_url'] ?? null,
            field: 'flaticon_url'
        );

        $characterClass->update($validated);

        return redirect()->back()->with('success', 'character_class_updated');
    }

    public function destroy(CharacterClass $characterClass): RedirectResponse
    {
        $this->deleteManagedImage($characterClass->icon_url);
        $this->deleteManagedImage($characterClass->flaticon_url);

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

    private function downloadImageIfPresent(?string $url, string $field): ?string
    {
        if (blank($url)) {
            return null;
        }

        $response = $this->fetchImageResponse($url, $field);
        $path = $this->storeImageResponse($response, $url);

        return Storage::disk('public')->url($path);
    }

    private function replaceImageIfPresent(?string $currentUrl, ?string $newUrl, string $field): ?string
    {
        if (blank($newUrl)) {
            $this->deleteManagedImage($currentUrl);

            return null;
        }

        if ($newUrl === $currentUrl) {
            return $currentUrl;
        }

        $downloadedUrl = $this->downloadImageIfPresent($newUrl, $field);

        $this->deleteManagedImage($currentUrl);

        return $downloadedUrl;
    }

    private function fetchImageResponse(string $url, string $field): Response
    {
        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                $field => 'Unable to download image from the provided URL.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                $field => 'Unable to download image from the provided URL.',
            ]);
        }

        $contentType = (string) $response->header('Content-Type');

        if (! str_starts_with(strtolower($contentType), 'image/')) {
            throw ValidationException::withMessages([
                $field => 'The provided URL must point to an image.',
            ]);
        }

        return $response;
    }

    private function storeImageResponse(Response $response, string $sourceUrl): string
    {
        $extension = $this->resolveImageExtension($response, $sourceUrl);
        $path = 'character-classes/'.Str::uuid().'.'.$extension;

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    private function resolveImageExtension(Response $response, string $sourceUrl): string
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        $extension = match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION),
        };

        return filled($extension) ? strtolower($extension) : 'png';
    }

    private function deleteManagedImage(?string $url): void
    {
        if (blank($url)) {
            return;
        }

        $path = $this->storagePathFromUrl($url);

        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function storagePathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $storagePrefix = '/storage/character-classes/';

        if (! str_starts_with($path, $storagePrefix)) {
            return null;
        }

        return Str::after($path, '/storage/');
    }
}
