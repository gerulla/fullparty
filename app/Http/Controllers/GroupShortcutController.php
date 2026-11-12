<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGroupQuickCreateShortcutsRequest;
use App\Models\Group;
use App\Services\Groups\GroupQuickCreateShortcutService;
use Illuminate\Http\RedirectResponse;

class GroupShortcutController extends Controller
{
    public function __construct(private readonly GroupQuickCreateShortcutService $shortcutService) {}

    public function update(UpdateGroupQuickCreateShortcutsRequest $request, Group $group): RedirectResponse
    {
        $group->loadMissing(['memberships', 'quickCreateShortcuts']);
        $this->authorizeAdminAccess($group);

        $this->shortcutService->replace(
            $group,
            $request->validated('shortcuts'),
            $request->user(),
        );

        return redirect()->back();
    }

    private function authorizeAdminAccess(Group $group): void
    {
        if (! $group->hasAdminAccess(auth()->id())) {
            abort(403);
        }
    }
}
