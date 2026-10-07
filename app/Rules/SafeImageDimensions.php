<?php

namespace App\Rules;

use App\Support\Images\ImageDimensions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class SafeImageDimensions implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $dimensions = @getimagesize($value->getPathname());
        if ($dimensions !== false && ! ImageDimensions::allowed($dimensions[0], $dimensions[1])) {
            $fail(ImageDimensions::errorMessage());
        }
    }
}
