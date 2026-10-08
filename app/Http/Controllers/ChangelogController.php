<?php

namespace App\Http\Controllers;

use App\Models\ChangelogEntry;
use App\Services\Changelog\ChangelogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChangelogController extends Controller
{
    public function __construct(private readonly ChangelogReader $reader) {}

    public function index(): JsonResponse
    {
        return response()->json(ChangelogEntry::published()->latest('published_at')->latest('id')->simplePaginate(10)->through($this->reader->summaryEntry(...)))
            ->header('Cache-Control', 'no-store');
    }

    public function latest(): JsonResponse
    {
        $id = $this->reader->latestId();
        $entry = $id ? ChangelogEntry::published()->find($id) : null;

        return response()->json(['entry' => $entry ? $this->reader->entry($entry) : null])->header('Cache-Control', 'no-store');
    }

    public function show(ChangelogEntry $changelogEntry): JsonResponse
    {
        abort_unless($changelogEntry->is_published, 404);

        return response()->json(['entry' => $this->reader->entry($changelogEntry)])->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, ChangelogEntry $changelogEntry): Response
    {
        $this->reader->markRead($request->user(), $changelogEntry);

        return response()->noContent();
    }
}
