<?php

namespace App\Http\Controllers;

use App\Services\RichText\GameIconCatalog;
use Illuminate\Http\JsonResponse;

class GameIconController extends Controller
{
    public function __invoke(GameIconCatalog $catalog): JsonResponse
    {
        return response()->json(['icons' => $catalog->all()])->header('Cache-Control', 'private, max-age=3600');
    }
}
