<?php

namespace App\Http\Controllers;

use App\Models\PhantomJob;
use App\Services\ManagedImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PhantomJobController extends Controller
{
    private const IMAGE_DIRECTORY = 'phantom-jobs';

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
        $validated['black_icon_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['black_icon_url'] ?? null,
            'black_icon_url',
            self::IMAGE_DIRECTORY
        );
        $validated['transparent_icon_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['transparent_icon_url'] ?? null,
            'transparent_icon_url',
            self::IMAGE_DIRECTORY
        );
        $validated['sprite_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['sprite_url'] ?? null,
            'sprite_url',
            self::IMAGE_DIRECTORY
        );

        PhantomJob::create($validated);

        return redirect()->back()->with('success', 'phantom_job_created');
    }

    public function show(PhantomJob $phantomJob): RedirectResponse
    {
        return redirect()->route('admin.character-data');
    }

    public function update(Request $request, PhantomJob $phantomJob): RedirectResponse
    {
        $validated = $request->validate($this->rules($phantomJob->id));

        $validated['icon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $phantomJob->icon_url,
            newUrl: $validated['icon_url'] ?? null,
            field: 'icon_url',
            directory: self::IMAGE_DIRECTORY
        );
        $validated['black_icon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $phantomJob->black_icon_url,
            newUrl: $validated['black_icon_url'] ?? null,
            field: 'black_icon_url',
            directory: self::IMAGE_DIRECTORY
        );
        $validated['transparent_icon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $phantomJob->transparent_icon_url,
            newUrl: $validated['transparent_icon_url'] ?? null,
            field: 'transparent_icon_url',
            directory: self::IMAGE_DIRECTORY
        );
        $validated['sprite_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $phantomJob->sprite_url,
            newUrl: $validated['sprite_url'] ?? null,
            field: 'sprite_url',
            directory: self::IMAGE_DIRECTORY
        );

        $phantomJob->update($validated);

        return redirect()->back()->with('success', 'phantom_job_updated');
    }

    public function destroy(PhantomJob $phantomJob): RedirectResponse
    {
        $this->managedImageStorage->deleteManagedImage($phantomJob->icon_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($phantomJob->black_icon_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($phantomJob->transparent_icon_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($phantomJob->sprite_url, self::IMAGE_DIRECTORY);

        $phantomJob->delete();

        return redirect()->back()->with('success', 'phantom_job_deleted');
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function rules(?int $phantomJobId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('phantom_jobs', 'name')->ignore($phantomJobId),
            ],
            'max_level' => ['required', 'integer', 'min:1'],
            'icon_url' => ['nullable', 'url', 'max:500'],
            'black_icon_url' => ['nullable', 'url', 'max:500'],
            'transparent_icon_url' => ['nullable', 'url', 'max:500'],
            'sprite_url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
