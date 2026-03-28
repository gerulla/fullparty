<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCharacterRequest;
use App\Http\Requests\UpdateCharacterRequest;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CharacterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response|JsonResponse
    {
        // TODO: Implement character listing logic
        // - Filter by user
        // - Include field values if needed
        // - Pagination
        // - Search/filtering
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        // TODO: Implement character creation form
        // - Load active field definitions
        // - Return Inertia view with form data
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
     * Verify a character using the provided token.
     */
    public function verify(Request $request, Character $character): JsonResponse
    {
        // TODO: Implement character verification logic
        // Steps:
        // 1. Validate token
        // 2. Check expiration
        // 3. Set verified_at timestamp
        // 4. Clear token and expires_at
        // 5. Return success response
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
