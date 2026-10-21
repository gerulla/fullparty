<?php

namespace App\Http\Controllers;

use App\Services\AccountFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountFlagController extends Controller
{
    public function __invoke(Request $request, AccountFlagService $flags): JsonResponse
    {
        return response()->json(['flags' => $flags->forUser($request->user())])
            ->header('Cache-Control', 'no-store, private');
    }
}
