<?php

namespace App\Http\Controllers;

use App\Models\CharacterTransfer;
use App\Services\Characters\CharacterTransferCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CharacterTransferCompletionController extends Controller
{
    public function store(Request $request, CharacterTransfer $transfer, CharacterTransferCompletionService $completion): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless((int) $transfer->to_user_id === (int) $request->user()->id
            && (int) $transfer->character?->user_id === (int) $request->user()->id, 404);
        $data = $request->validate([
            'history' => ['required', Rule::in(['keep', 'discard'])],
            'upcoming' => ['required', Rule::in(['keep', 'discard'])],
        ]);
        $completion->complete($transfer, $request->user(), $data['history'], $data['upcoming']);

        return response()->json(['completed' => true])->header('Cache-Control', 'no-store, private');
    }
}
