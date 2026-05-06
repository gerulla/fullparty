<?php

namespace App\Http\Controllers;

use App\Exceptions\LodestoneFetchException;
use App\Exceptions\LodestoneInvalidInputException;
use App\Exceptions\LodestoneParseException;
use App\Http\Requests\StoreCharacterRequest;
use App\Http\Requests\StoreXIVAuthCharacterRequest;
use App\Http\Requests\UpdateCharacterRequest;
use App\Models\Character;
use App\Models\CharacterClass;
use App\Models\ActivityApplication;
use App\Models\PhantomJob;
use App\Services\AuditLogger;
use App\Services\Characters\CharacterProfileRefreshService;
use App\Services\Lodestone\LodestoneInputNormalizer;
use App\Services\Lodestone\LodestoneScraper;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Redirect;

class CharacterController extends Controller
{
	public function __construct(
		private readonly AuditLogger $auditLogger,
		private readonly CharacterProfileRefreshService $characterProfileRefreshService,
	) {}

	private function generateVerificationToken(): string
	{
		return 'FP-' . strtoupper(Str::random(10));
	}
 
	public function exists(Request $request): \Illuminate\Http\RedirectResponse
	{
		$validated = $request->validate([
			'lodestone_id' => ['required', 'string'],
		]);
		
		$scraper = app(LodestoneScraper::class);
		$inputNormalizer = app(LodestoneInputNormalizer::class);
		$lodestoneId = $inputNormalizer->extractLodestoneId($validated['lodestone_id']);
		//If character exists and has been verified, tell the user the character is taken
		$character = Character::where('lodestone_id', $lodestoneId)->first();
		
		if ($character && $character->isVerified()) {
			return Redirect::back()->with('flash_data', [
				'manual_character_lookup' => [
					'taken' => true,
				]
			]);
		}
		// If the character exists but has not been verified, tell the user to claim it
		if($character){
			$this->renewVerificationTokenIfNeeded($character);
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
			$token = $this->generateVerificationToken();
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
		
		if($character->isVerified()){
			return Redirect::back()->with('flash_data', [
				'character_verification' => [
					'taken' => true,
				]
			]);
		}
		if(blank($character->token) || $character->isTokenExpired()){
			return Redirect::back()->withErrors([
				'error' => 'expired_token'
			]);
		}
		
		$scraper = app(LodestoneScraper::class);
		// If the character does not exist, scrape it and create it
		try {
			$data = $scraper->scrapeProfile($character->lodestone_id, ignoreCache: true);
			if(!$data->bio || !str_contains($data->bio, $character->token)){
				return Redirect::back()->withErrors([
					'error' => 'invalid_token'
				]);
			}
			$this->finalizeCharacterVerification($character, auth()->user(), 'lodestone_token');

			$this->auditLogger->log(
				action: 'character.verified',
				severity: AuditSeverity::INFO,
				scopeType: AuditScope::CHARACTER,
				scopeId: $character->id,
				message: 'audit_log.events.character.verified',
				actor: auth()->user(),
				subject: $character,
				metadata: [
					'verification_method' => 'lodestone_token',
					'lodestone_id' => $character->lodestone_id,
					'is_primary' => $character->is_primary,
				],
			);

			$this->refreshVerifiedCharacterOnce($character);
			
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
	
	public function fetchXIVAuthCharacters(Request $request){
		$xivauthSocial = $request->user()->socialAccounts()->where('provider', 'xivauth')->first();
		if(!$xivauthSocial){
			return Redirect::back()->withErrors([
				'error' => 'xivauth_not_linked',
			]);
		}
		try{
			$token = XIVAuthController::getValidXivAuthAccessToken($xivauthSocial);
		}catch (\Exception $exception){
			return Redirect::back()->withErrors([
				'error' => 'xivauth_token_invalid'
			]);
		}
		//Get User Data
		$response = Http::withHeaders([
			'Accept' => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		])->get('https://xivauth.net/api/v1/characters');
		
		$data = json_decode($response->getBody(), true);
		return Redirect::back()->with('flash_data', [
			'characters' => $data
		]);
	}
	
	public function importXIVAuthCharacter(StoreXIVAuthCharacterRequest $request){
		$validated = $request->validated();
		
		$is_primary = false;
		if (auth()->user()->characters()->count() === 0) {
			$is_primary = true;
		}
		$character = Character::where('lodestone_id', $validated['lodestone_id'])->first();
		// If character doesnt exist, create it
		if(!$character){
			$character = Character::create([
				'user_id' => auth()->id(),
				'name' => $validated['name'],
				'world' => $validated['world'],
				'datacenter' => $validated['datacenter'],
				'lodestone_id' => $validated['lodestone_id'],
				'avatar_url' => $validated['avatar_url'],
				'add_method' => 'xivauth',
				'is_primary' => $is_primary,
				'verified_at' => Carbon::now(),
			]);
		}else{
			$character->fill([
				'name' => $validated['name'],
				'world' => $validated['world'],
				'datacenter' => $validated['datacenter'],
				'avatar_url' => $validated['avatar_url'],
				'add_method' => 'xivauth',
			]);
		}

		$this->finalizeCharacterVerification($character, auth()->user(), 'xivauth', $is_primary);
		$this->refreshVerifiedCharacterOnce($character);

		$character->refresh();

		$this->auditLogger->log(
			action: 'character.verified',
			severity: AuditSeverity::INFO,
			scopeType: AuditScope::CHARACTER,
			scopeId: $character->id,
			message: 'audit_log.events.character.verified',
			actor: auth()->user(),
			subject: $character,
			metadata: [
				'verification_method' => 'xivauth',
				'lodestone_id' => $character->lodestone_id,
				'is_primary' => $character->is_primary,
			],
		);
		
		return Redirect::back()->with('flash_data', [
			'xivauth_character_import' => [
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

	public function refreshCharacterData(Character $character): \Illuminate\Http\RedirectResponse
	{
		if ($character->user_id !== auth()->id()) {
			abort(403);
		}

		$this->auditLogger->log(
			action: 'character.refresh_requested',
			severity: AuditSeverity::INFO,
			scopeType: AuditScope::CHARACTER,
			scopeId: $character->id,
			message: 'audit_log.events.character.refresh_requested',
			actor: auth()->user(),
			subject: $character,
			metadata: [
				'lodestone_id' => $character->lodestone_id,
				'name' => $character->name,
			],
		);

		try {
			$this->characterProfileRefreshService->refresh($character, ignoreCache: true);

			return Redirect::back()->with('success', 'character_data_refreshed');
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
		} catch (\Throwable $e) {
			return Redirect::back()->withErrors([
				'error' => 'character_refresh_failed',
			]);
		}
	}

	private function renewVerificationTokenIfNeeded(Character $character): void
	{
		if (!$character->isTokenExpired() && filled($character->token)) {
			return;
		}

		$character->update([
			'token' => $this->generateVerificationToken(),
			'expires_at' => Carbon::now()->addDay(),
		]);
	}

	private function finalizeCharacterVerification(
		Character $character,
		\App\Models\User $user,
		string $verificationMethod,
		?bool $isPrimaryOverride = null,
	): void {
		DB::transaction(function () use ($character, $user, $verificationMethod, $isPrimaryOverride): void {
			$isPrimary = $isPrimaryOverride ?? $user->characters()->count() === 0;

			$character->user_id = $user->id;
			$character->verified_at = Carbon::now();
			$character->token = null;
			$character->expires_at = null;
			$character->is_primary = $isPrimary;
			$character->add_method = $verificationMethod === 'xivauth' ? 'xivauth' : 'manual';
			$character->save();

			$this->claimCharacterApplications($character, $user);
		});
	}

	private function claimCharacterApplications(Character $character, \App\Models\User $user): void
	{
		$applications = ActivityApplication::query()
			->whereNull('user_id')
			->where('applicant_lodestone_id', $character->lodestone_id)
			->lockForUpdate()
			->get();

		if ($applications->isEmpty()) {
			return;
		}

		$conflictingActivityIds = ActivityApplication::query()
			->where('user_id', $user->id)
			->whereIn('activity_id', $applications->pluck('activity_id'))
			->pluck('activity_id')
			->all();

		foreach ($applications as $application) {
			if (in_array($application->activity_id, $conflictingActivityIds, true)) {
				Log::warning('Skipping guest application claim because the user already has an application for the activity.', [
					'application_id' => $application->id,
					'activity_id' => $application->activity_id,
					'user_id' => $user->id,
					'character_id' => $character->id,
					'lodestone_id' => $character->lodestone_id,
				]);

				continue;
			}

			$application->update([
				'user_id' => $user->id,
				'selected_character_id' => $character->id,
				'guest_access_token' => null,
			]);
		}
	}

	private function refreshVerifiedCharacterOnce(Character $character): void
	{
		try {
			$this->characterProfileRefreshService->refresh($character, ignoreCache: true);
		} catch (\Throwable $exception) {
			Log::warning('Unable to auto-refresh character data after verification.', [
				'character_id' => $character->id,
				'lodestone_id' => $character->lodestone_id,
				'exception' => $exception->getMessage(),
			]);
		}
	}

	public function markPreferredClass(Request $request, Character $character): \Illuminate\Http\RedirectResponse
	{
		if ($character->user_id !== auth()->id()) {
			abort(403);
		}

		$validated = $request->validate([
			'character_class_id' => ['required', 'exists:character_classes,id'],
			'is_preferred' => ['required', 'boolean'],
		]);

		$existingProgress = $character->classes()
			->where('character_classes.id', $validated['character_class_id'])
			->first();

		$character->classes()->syncWithoutDetaching([
			$validated['character_class_id'] => [
				'level' => $existingProgress?->pivot?->level ?? 0,
				'is_preferred' => $validated['is_preferred'],
			],
		]);

		return Redirect::back()->with('success', $validated['is_preferred']
			? 'character_class_marked_preferred'
			: 'character_class_unmarked_preferred');
	}

	public function markPreferredPhantomJob(Request $request, Character $character): \Illuminate\Http\RedirectResponse
	{
		if ($character->user_id !== auth()->id()) {
			abort(403);
		}

		$validated = $request->validate([
			'phantom_job_id' => ['required', 'exists:phantom_jobs,id'],
			'is_preferred' => ['required', 'boolean'],
		]);

		$existingProgress = $character->phantomJobs()
			->where('phantom_jobs.id', $validated['phantom_job_id'])
			->first();

		$character->phantomJobs()->syncWithoutDetaching([
			$validated['phantom_job_id'] => [
				'current_level' => $existingProgress?->pivot?->current_level ?? 0,
				'is_preferred' => $validated['is_preferred'],
			],
		]);

		return Redirect::back()->with('success', $validated['is_preferred']
			? 'phantom_job_marked_preferred'
			: 'phantom_job_unmarked_preferred');
	}

	public function makePrimary(Character $character): \Illuminate\Http\RedirectResponse
	{
		if ($character->user_id !== auth()->id()) {
			abort(403);
		}

		if (!$character->isVerified()) {
			return Redirect::back()->withErrors([
				'error' => 'character_not_verified',
			]);
		}

		DB::transaction(function () use ($character) {
			Character::query()
				->where('user_id', auth()->id())
				->where('is_primary', true)
				->update(['is_primary' => false]);

			$character->update(['is_primary' => true]);
		});

		$this->auditLogger->log(
			action: 'character.made_primary',
			severity: AuditSeverity::INFO,
			scopeType: AuditScope::CHARACTER,
			scopeId: $character->id,
			message: 'audit_log.events.character.made_primary',
			actor: auth()->user(),
			subject: $character->fresh(),
			metadata: [
				'lodestone_id' => $character->lodestone_id,
				'name' => $character->name,
				'is_primary' => true,
			],
		);

		return Redirect::back()->with('success', 'character_marked_primary');
	}
	
	/**
	 * List all characters the user has registered
	 */
	public function list()
	{
		$characterClasses = CharacterClass::query()
			->orderBy('role')
			->orderBy('name')
			->get();

		$phantomJobs = PhantomJob::query()
			->orderBy('name')
			->get();

		$characters = auth()->user()->characters()
			->with([
				'fieldValues.fieldDefinition',
				'classes',
				'phantomJobs',
				'occultProgress',
			])
			->get()
			->map(fn (Character $character) => $this->transformCharacterForCard($character, $characterClasses, $phantomJobs));

		return Inertia::render('Dashboard/Account/MyCharacters', [
			'characters' => $characters,
		]);
	}

	private function transformCharacterForCard(Character $character, Collection $characterClasses, Collection $phantomJobs): array
	{
		$classProgress = $character->classes->keyBy('id');
		$phantomJobProgress = $character->phantomJobs->keyBy('id');

		return [
			'id' => $character->id,
			'is_primary' => $character->is_primary,
			'name' => $character->name,
			'world' => $character->world,
			'datacenter' => $character->datacenter,
			'lodestone_id' => $character->lodestone_id,
			'avatar_url' => $character->avatar_url,
			'verified_at' => $character->verified_at,
			'add_method' => $character->add_method,
			'classes' => $characterClasses->map(function (CharacterClass $characterClass) use ($character, $classProgress) {
				$progress = $classProgress->get($characterClass->id);
				$level = $progress?->pivot?->level ?? $this->resolveCharacterClassLevel($character, $characterClass->shorthand);

				return [
					'id' => $characterClass->id,
					'name' => $characterClass->name,
					'shorthand' => $characterClass->shorthand,
					'icon_url' => $characterClass->icon_url,
					'role' => $characterClass->role,
					'level' => $level,
					'is_preferred' => $progress?->pivot?->is_preferred ?? false,
				];
			})->values(),
			'occult' => [
				'knowledge_level' => $character->occultProgress?->knowledge_level ?? 0,
				'blood_progress' => $character->occultProgress?->forkedTowerBloodProgress() ?? $this->emptyForkedTowerBloodProgress(),
				'phantom_jobs' => $phantomJobs->map(function (PhantomJob $phantomJob) use ($phantomJobProgress) {
					$progress = $phantomJobProgress->get($phantomJob->id);
					$currentLevel = $progress?->pivot?->current_level ?? 0;
					$isMaxed = $currentLevel >= $phantomJob->max_level;

					return [
						'id' => $phantomJob->id,
						'name' => $phantomJob->name,
						'icon_url' => $phantomJob->icon_url,
						'black_icon_url' => $phantomJob->black_icon_url,
						'current_level' => $currentLevel,
						'max_level' => $phantomJob->max_level,
						'is_preferred' => $progress?->pivot?->is_preferred ?? false,
						'is_maxed' => $isMaxed,
					];
				})->values(),
			],
		];
	}

	private function getLoadedFieldValue(Character $character, string $slug): mixed
	{
		$fieldValue = $character->fieldValues
			->first(fn ($fieldValue) => $fieldValue->fieldDefinition?->slug === $slug);

		return $fieldValue?->getCastedValue();
	}

	private function resolveCharacterClassLevel(Character $character, string $shorthand): int
	{
		$normalizedShorthand = strtolower($shorthand);

		return (int) (
			$this->getLoadedFieldValue($character, sprintf('job.%s.level', $normalizedShorthand))
			?? $this->getLoadedFieldValue($character, sprintf('%s_level', $normalizedShorthand))
			?? 0
		);
	}

	private function emptyForkedTowerBloodProgress(): array
	{
		return [
			'clears' => 0,
			'bosses' => [
				['key' => 'demon_tablet', 'kills' => 0, 'progress' => 0],
				['key' => 'dead_stars', 'kills' => 0, 'progress' => 0],
				['key' => 'marble_dragon', 'kills' => 0, 'progress' => 0],
				['key' => 'magitaur', 'kills' => 0, 'progress' => 0],
			],
		];
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

}
