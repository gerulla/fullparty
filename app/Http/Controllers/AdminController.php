<?php

namespace App\Http\Controllers;

use App\Models\CharacterClass;
use App\Models\CharacterFieldDefinition;
use Inertia\Inertia;

class AdminController extends Controller
{
    /**
     * Display the consolidated character data admin page.
     */
    public function characterData()
    {
        return Inertia::render('Admin/CharacterData', [
            'definitions' => CharacterFieldDefinition::ordered()->get(),
            'characterClasses' => CharacterClass::query()
                ->orderBy('role')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
