<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiDocumentationController extends Controller
{
    public function index(Request $request): View
    {
        $locale = $request->query('lang', app()->getLocale());
        if (in_array($locale, ['en', 'de', 'fr', 'ja'], true)) {
            app()->setLocale($locale);
        }

        return view('api-docs', ['websiteUrl' => config('app.url')]);
    }

    public function specification(Request $request): JsonResponse
    {
        $document = json_decode(file_get_contents(resource_path('openapi/fullparty.json')), true, flags: JSON_THROW_ON_ERROR);
        $document['servers'] = [['url' => $request->getSchemeAndHttpHost(), 'description' => 'FullParty']];

        return response()->json($document, 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
