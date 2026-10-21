<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class GroupMembershipRequestController extends Controller
{
    public function index(): RedirectResponse
    {
        // Keep old bookmarks usable after membership requests are retired.
        return redirect()->route('groups.index');
    }
}
