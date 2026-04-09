<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserController extends Controller
{
    public function changeUsername(Request $request)
    {
		$validated = $request->validate([
			'username' => ['required', 'string', 'max:255'],
		]);
        $request->user()->update(['name' => $request->username]);
		return redirect()
			->route('settings')
			->with('success', ['username_updated', $validated['username']]);
    }
	
	public function changeNotificationSettings(Request $request)
	{
		$validated = $request->validate([
			'run_reminders' => ['required', 'boolean'],
			'application_notifications' => ['required', 'boolean'],
			'group_updates' => ['required', 'boolean'],
			'assignment_updates' => ['required', 'boolean'],
			'email_notifications' => ['required', 'boolean'],
			'discord_notifications' => ['required', 'boolean'],
		]);
		// If the user doesn't have a discord account, disable discord notifications'
		if($request->user()->socialAccounts->where('provider', 'discord')->isEmpty()){
			$validated['discord_notifications'] = false;
		}
		
		$request->user()->update($validated);
		return redirect()
			->route('settings')
			->with('success', ['notification_settings_updated']);
		
	}
	
	public function changePrivacySettings(Request $request)
	{
		$validated = $request->validate([
			'public_profile' => ['required', 'boolean'],
			'public_characters' => ['required', 'boolean'],
		]);
		
		$request->user()->update($validated);
		return redirect()
			->route('settings')
			->with('success', ['privacy_settings_updated']);
	}
}
