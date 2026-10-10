<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImageTransformRequest;
use App\Services\Images\ImageVariantService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImageTransformController extends Controller
{
    public function __invoke(ImageTransformRequest $request, ImageVariantService $images): BinaryFileResponse
    {
        $file = $images->variant($request->validated('path'), $request->options(), (string) $request->ip());
        $response = response()->file($file, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ])->setEtag(pathinfo($file, PATHINFO_FILENAME));
        $response->isNotModified($request);

        return $response;
    }
}
