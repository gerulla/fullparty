<?php

namespace App\Http\Controllers;

use App\Exceptions\LodestoneFetchException;
use App\Exceptions\LodestoneInvalidInputException;
use App\Exceptions\LodestoneParseException;
use App\Http\Requests\StoreCharacterRequest;
use App\Http\Requests\UpdateCharacterRequest;
use App\Models\Character;
use App\Services\Lodestone\LodestoneScraper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Redirect;

class CharacterController extends Controller
{
 
	public function exists(Request $request): \Illuminate\Http\RedirectResponse
	{
		$validated = $request->validate([
			'lodestone_id' => ['required', 'string'],
		]);
		
		$scraper = app(LodestoneScraper::class);
		//If character exists and has been verified, tell the user the character is taken
		$character = Character::where('lodestone_id', $validated['lodestone_id'])->first();
		if ($character && $character->isVerified()) {
			return Redirect::back()->with('flash_data', [
				'taken' => true,
			]);
		// If the character exists but has not been verified, tell the user to claim it
		}else if($character){
			//Renew token if expired
			if($character->isTokenExpired()){
				$token = "FP-" . strtoupper(Str::random(10));
				$character->update([
					'token' => $token,
					'expires_at' => Carbon::now()->addDay(),
				]);
			}
			return Redirect::back()->with('flash_data', [
				'manual_character_lookup' => [
					'exists' => true,
					'taken' => false,
					'character' => [
						'id' => $character->id,
						'lodestone_id' => $character->lodestone_id,
						'name' => $character->name,
						'world' => $character->world,
						'datacenter' => $character->datacenter,
						'avatar' => $character->avatar_url,
						'token' => $character->token,
					],
				],
			]);
		}
		// If the character does not exist, scrape it and create it
		try {
			$data = $scraper->scrapeProfile($validated['lodestone_id']);
			$token = "FP-" . strtoupper(Str::random(10));
			$character = Character::create([
				'user_id' => auth()->id(),
				'name' => $data->name,
				'world' => $data->world,
				'datacenter' => $data->dataCenter,
				'lodestone_id' => $data->lodestoneId,
				'avatar_url' => $data->avatarUrl,
				'token' => $token,
				'expires_at' => Carbon::now()->addDay(),
			]);
			
			$character->save();
			
			return Redirect::back()->with('flash_data', [
				'manual_character_lookup' => [
					'exists' => true,
					'taken' => false,
					'character' => [
						'id' => $character->id,
						'lodestone_id' => $character->lodestone_id,
						'name' => $character->name,
						'world' => $character->world,
						'datacenter' => $character->datacenter,
						'avatar' => $character->avatar_url,
						'token' => $character->token,
					],
				],
			]);
		} catch (LodestoneInvalidInputException $e) {
			return Redirect::back()->withErrors([
				'error' => 'invalid_lodestone_id',
			]);
		} catch (LodestoneFetchException $e) {
			return Redirect::back()->withErrors([
				'error' => $e->getCode() === 404
					? 'character_not_found'
					: 'lodestone_error',
			]);
		} catch (LodestoneParseException $e) {
			return Redirect::back()->withErrors([
				'error' => 'parse_error',
			]);
		}
	}
	
	public function verify(Request $request): \Illuminate\Http\RedirectResponse
	{
		$validated = $request->validate([
			'token' => ['required', 'string'],
			'character_id' => ['required', 'exists:characters,id'],
		]);
		$character = Character::find($validated['character_id']);
		$scraper = app(LodestoneScraper::class);
		// If the character does not exist, scrape it and create it
		try {
			$data = $scraper->scrapeProfile($character->lodestone_id, ignoreCache: true);
			if(!$data->bio || !str_contains($data->bio, $character->token)){
				return Redirect::back()->withErrors([
					'error' => 'invalid_token'
				]);
			}
			if(count(auth()->user()->characters) == 1){
				$character->is_primary = true;
			}
			$character->user_id = auth()->id();
			$character->verified_at = Carbon::now();
			$character->token = null;
			$character->save();
			
			return Redirect::back()->with('flash_data', [
				'character_verification' => [
					'success' => true,
				],
			]);
		} catch (LodestoneInvalidInputException $e) {
			return Redirect::back()->withErrors([
				'error' => 'invalid_lodestone_id',
			]);
		} catch (LodestoneFetchException $e) {
			return Redirect::back()->withErrors([
				'error' => $e->getCode() === 404
					? 'character_not_found'
					: 'lodestone_error',
			]);
		} catch (LodestoneParseException $e) {
			return Redirect::back()->withErrors([
				'error' => 'parse_error',
			]);
		}
	}
	
	/**
	 * List all characters the user has registered
	 */
	public function list()
	{
		return Inertia::render('Dashboard/Account/MyCharacters', [
			'characters' => auth()->user()->characters()->with('fieldValues')->get(),
		]);
	}
	
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCharacterRequest $request): JsonResponse
    {
        // TODO: Implement character creation logic
        // Steps:
        // 1. Create character with validated data
        // 2. Associate with authenticated user
        // 3. Handle dynamic field values if provided
        // 4. Return created character resource
    }

    /**
     * Display the specified resource.
     */
    public function show(Character $character): Response|JsonResponse
    {
        // TODO: Implement character detail view
        // - Load character with relationships
        // - Include field values with definitions
        // - Check authorization
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Character $character): Response
    {
        // TODO: Implement character edit form
        // - Load character with field values
        // - Load active field definitions
        // - Check authorization
        // - Return Inertia view with form data
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCharacterRequest $request, Character $character): JsonResponse
    {
        // TODO: Implement character update logic
        // Steps:
        // 1. Update character with validated data
        // 2. Handle dynamic field values if provided
        // 3. Return updated character resource
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Character $character): JsonResponse
    {
        // TODO: Implement character deletion logic
        // - Check authorization
        // - Delete character (cascade will handle field values)
        // - Return success response
    }

    /**
     * Regenerate verification token for a character.
     */
    public function regenerateToken(Character $character): JsonResponse
    {
        // TODO: Implement token regeneration logic
        // Steps:
        // 1. Generate new token
        // 2. Set new expiration
        // 3. Clear verified_at
        // 4. Save and return
    }

    /**
     * Get field values for a specific character.
     */
    public function fieldValues(Character $character): JsonResponse
    {
        // TODO: Implement field values retrieval
        // - Load field values with definitions
        // - Return formatted response
    }

    /**
     * Update field values for a specific character.
     */
    public function updateFieldValues(Request $request, Character $character): JsonResponse
    {
        // TODO: Implement field values update
        // Steps:
        // 1. Validate field values against definitions
        // 2. Update or create field values
        // 3. Return updated values
    }
}
