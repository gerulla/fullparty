<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
	/**
	 * Display the user's settings page.
	 *
	 * @return \Inertia\Response
	 */
    public function index()
	{
		return Inertia::render('Dashboard/Settings/Index');
	}
}
