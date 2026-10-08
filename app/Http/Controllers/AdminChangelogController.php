<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveChangelogRequest;
use App\Models\ChangelogEntry;
use App\Services\Changelog\ChangelogService;
use App\Services\Changelog\ChangelogVersions;
use App\Services\RichText\RichTextDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminChangelogController extends Controller
{
    public function __construct(private readonly ChangelogService $changelogs, private readonly ChangelogVersions $versions) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Changelog/Index', [
            'entries' => ChangelogEntry::query()->latest('id')->paginate(20)->through(fn ($entry) => [
                'id' => $entry->id, 'title' => $entry->translations['en']['title'],
                'version_from' => $entry->version_from, 'version_to' => $entry->version_to,
                'is_published' => $entry->is_published, 'published_at' => $entry->published_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return $this->editor();
    }

    public function edit(ChangelogEntry $changelogEntry): Response
    {
        return $this->editor($changelogEntry);
    }

    private function editor(?ChangelogEntry $entry = null): Response
    {
        return Inertia::render('Admin/Changelog/Edit', [
            'entry' => $entry ? $entry->only(['id', 'translations', 'version_mode', 'version_from', 'version_to', 'commit', 'baseline_id', 'is_published', 'published_at', 'revision']) : null,
            'versionChoices' => $this->versions->choices(),
            'emptyDocument' => RichTextDocument::empty(),
        ]);
    }

    public function store(SaveChangelogRequest $request): RedirectResponse
    {
        $entry = $this->changelogs->save($request->user(), $request->validated());

        return to_route('admin.changelog.edit', $entry)->with('success', 'changelog_saved');
    }

    public function update(SaveChangelogRequest $request, ChangelogEntry $changelogEntry): RedirectResponse
    {
        $this->changelogs->save($request->user(), $request->validated(), $changelogEntry);

        return back()->with('success', 'changelog_saved');
    }

    public function publish(Request $request, ChangelogEntry $changelogEntry): RedirectResponse
    {
        return $this->visibility($request, $changelogEntry, true);
    }

    public function unpublish(Request $request, ChangelogEntry $changelogEntry): RedirectResponse
    {
        return $this->visibility($request, $changelogEntry, false);
    }

    private function visibility(Request $request, ChangelogEntry $entry, bool $published): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->changelogs->visibility($request->user(), $entry, (int) $data['revision'], $published);

        return back()->with('success', $published ? 'changelog_published' : 'changelog_unpublished');
    }
}
